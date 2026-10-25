<?php

namespace Tests\Feature;

use App\Actions\Items\ReceiveFoundItem;
use App\Models\Category;
use App\Models\Person;
use App\Models\Staff;
use App\Models\StorageLocation;
use App\Models\User;
use App\Notifications\ResetUserPassword;
use App\Notifications\VerifyUserEmail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserFacingFeaturesTest extends TestCase
{
    private User $staffUser;
    private User $user;
    private Person $person;
    private Category $category;
    private StorageLocation $location;
    private int $itemId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('reclaim_test_claims', DB::selectOne('SELECT DATABASE() AS name')->name);
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function (): void { while (DB::transactionLevel() > 0) DB::rollBack(); });
        Notification::fake();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-01 12:00:00.123456', 'Asia/Manila'));
        $this->beforeApplicationDestroyed(fn () => CarbonImmutable::setTestNow());

        $staff = Staff::create(['full_name' => 'Fictional User Workflow Staff']);
        $this->staffUser = User::factory()->create();
        $this->staffUser->staff()->associate($staff)->save();
        $this->person = Person::create(['full_name' => 'Fictional Account Owner', 'contact_details' => 'owner@example.test']);
        $this->user = User::factory()->create(['name' => $this->person->full_name, 'email' => 'owner@example.test']);
        $this->user->person()->associate($this->person)->save();
        $this->category = Category::create(['category_name' => 'User workflow category']);
        $this->location = StorageLocation::create(['location_code' => 'USR-A', 'location_description' => 'Fictional user test cabinet']);
        $this->itemId = app(ReceiveFoundItem::class)->execute([
            'finder_id' => $this->person->person_id, 'category_id' => $this->category->category_id,
            'initial_location_id' => $this->location->location_id, 'item_description' => 'Private internal item description',
            'found_place' => 'Private exact room', 'found_at' => '2026-10-01T09:00:00+08:00',
        ], $staff->staff_id)->item_id;
    }

    private function staff(): void { Sanctum::actingAs($this->staffUser, ['staff']); }
    private function user(): void { Sanctum::actingAs($this->user, ['user']); }

    public function test_registration_creates_a_new_private_person_link_and_verification_mail(): void
    {
        $existing = Person::create(['full_name' => 'Same Name', 'contact_details' => 'same@example.test']);
        $this->postJson('/api/auth/register', [
            'full_name' => 'Same Name', 'contact_details' => 'same@example.test', 'email' => 'NEW@example.test',
            'password' => 'A-secure-test-password-123', 'password_confirmation' => 'A-secure-test-password-123',
        ])->assertCreated()->assertJsonPath('data.email_verified', false)->assertJsonMissingPath('data.password');
        $registered = User::query()->where('email', 'new@example.test')->firstOrFail();
        $this->assertNotSame((int) $existing->person_id, (int) $registered->person_id);
        $this->assertDatabaseHas('person', ['person_id' => $registered->person_id, 'contact_details' => 'same@example.test']);
        $this->assertFalse((bool) $registered->is_account_admin);
        $this->assertNull($registered->staff_id);
        Notification::assertSentTo($registered, VerifyUserEmail::class);
        $peopleBeforeDuplicate = DB::table('person')->count();
        $this->postJson('/api/auth/register', [
            'full_name' => 'Duplicate email person', 'contact_details' => 'duplicate@example.test', 'email' => 'NEW@example.test',
            'password' => 'A-secure-test-password-123', 'password_confirmation' => 'A-secure-test-password-123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame($peopleBeforeDuplicate, DB::table('person')->count());
        $this->postJson('/api/auth/register', [
            'full_name' => 'Escalation', 'contact_details' => 'attacker@example.test', 'email' => 'attacker@example.test',
            'password' => 'A-secure-test-password-123', 'password_confirmation' => 'A-secure-test-password-123',
            'is_account_admin' => true, 'staff_id' => 1, 'person_id' => $existing->person_id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['is_account_admin', 'staff_id', 'person_id']);
    }

    public function test_verification_login_profile_and_unverified_gate_work(): void
    {
        $unverified = User::factory()->unverified()->create();
        $privatePerson = Person::create(['full_name' => 'Unverified person', 'contact_details' => 'unverified@example.test']);
        $unverified->person()->associate($privatePerson)->save();
        $login = $this->postJson('/api/auth/login', ['email' => $unverified->email, 'password' => 'password'])->assertOk();
        $token = $login->json('token');
        $this->withToken($token)->getJson('/api/me/profile')->assertOk();
        $this->withToken($token)->getJson('/api/my/finder-reports')->assertForbidden();
        $unlinked = User::factory()->create();
        $unlinkedToken = $unlinked->createToken('unlinked-user', ['user'])->plainTextToken;
        $this->withToken($unlinkedToken)->getJson('/api/my/finder-reports')->assertForbidden();
        $wrongAbilityToken = $unverified->createToken('wrong-ability', ['staff'])->plainTextToken;
        $this->withToken($wrongAbilityToken)->getJson('/api/my/finder-reports')->assertForbidden();
        $this->withToken($token)->getJson('/api/my/finder-reports')->assertForbidden();

        $url = URL::temporarySignedRoute('user.email.verify', now()->addMinutes(30), ['id' => $unverified->id, 'hash' => sha1($unverified->email)]);
        $this->getJson($url)->assertOk();
        $this->assertNotNull($unverified->fresh()->email_verified_at);
        $this->getJson($url.'&tampered=1')->assertForbidden();

        $this->withToken($token)->patchJson('/api/me/profile', [
            'full_name' => 'Updated account name', 'contact_details' => 'new-contact@example.test', 'email' => 'new-email@example.test',
            'is_account_admin' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('is_account_admin');
        $this->withToken($token)->patchJson('/api/me/profile', [
            'full_name' => 'Updated account name', 'contact_details' => 'new-contact@example.test', 'email' => 'new-email@example.test',
        ])->assertOk()->assertJsonPath('data.person.full_name', 'Updated account name')->assertJsonPath('data.email', 'new-email@example.test')->assertJsonPath('data.email_verified', false);
        $this->assertDatabaseHas('users', ['id' => $unverified->id, 'name' => 'Updated account name', 'email' => 'new-email@example.test', 'email_verified_at' => null]);
        $this->assertDatabaseHas('person', ['person_id' => $privatePerson->person_id, 'full_name' => 'Updated account name', 'contact_details' => 'new-contact@example.test']);
    }

    public function test_public_listing_is_opt_in_private_and_users_claim_as_their_linked_person(): void
    {
        $this->getJson('/api/public/items')->assertOk()->assertJsonCount(0, 'data');
        $this->staff();
        $this->putJson('/api/staff/items/'.$this->itemId.'/public-listing', [
            'public_description' => 'A brown wallet with a silver clasp',
            'item_description' => 'attempt to leak internal description', 'location_id' => $this->location->location_id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['item_description', 'location_id']);
        $this->putJson('/api/staff/items/'.$this->itemId.'/public-listing', ['public_description' => 'A brown wallet with a silver clasp'])->assertCreated();
        $this->getJson('/api/public/items?q=wallet')->assertOk()->assertJsonPath('data.0.public_description', 'A brown wallet with a silver clasp')
            ->assertJsonMissingPath('data.0.item_description')->assertJsonMissingPath('data.0.finder_id')->assertJsonMissingPath('data.0.current_location_id');
        $this->user();
        $this->postJson('/api/my/items/'.$this->itemId.'/claims', ['evidence_text' => 'A unique scratch is mine', 'claimant_id' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors('claimant_id');
        $this->postJson('/api/my/items/'.$this->itemId.'/claims', ['evidence_text' => 'A unique scratch is mine'])->assertCreated();
        $this->assertDatabaseHas('claim', ['item_id' => $this->itemId, 'claimant_id' => $this->person->person_id, 'outcome' => 'PENDING']);
        $this->postJson('/api/my/items/'.$this->itemId.'/claims', ['evidence_text' => 'Duplicate'])->assertConflict();
        $this->getJson('/api/my/claims')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.evidence_text', 'A unique scratch is mine');
        $otherPerson = Person::create(['full_name' => 'Other user', 'contact_details' => 'other@example.test']);
        $other = User::factory()->create(); $other->person()->associate($otherPerson)->save();
        Sanctum::actingAs($other, ['user']);
        $this->getJson('/api/my/claims/'.$this->itemId)->assertNotFound();
        $this->postJson('/api/my/items/'.$this->itemId.'/claims', ['evidence_text' => 'Other evidence'])->assertCreated();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-01 12:05:00.654321', 'Asia/Manila'));
        $this->staff();
        $this->postJson('/api/items/'.$this->itemId.'/claims/'.$this->person->person_id.'/return', [])->assertOk();
        $this->getJson('/api/public/items')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_finder_handover_is_a_single_staff_intake_and_own_reports_are_scoped(): void
    {
        $this->user();
        $before = DB::table('found_item')->count();
        $created = $this->postJson('/api/my/finder-reports', [
            'item_description' => 'A reported blue umbrella', 'found_place' => 'Campus lobby',
            'found_at' => '2026-10-01T10:00:00+08:00', 'category_id' => $this->category->category_id,
        ])->assertCreated()->assertJsonPath('data.status', 'PENDING');
        $report = $created->json('data.report_id');
        $this->assertSame($before, DB::table('found_item')->count());
        $otherPerson = Person::create(['full_name' => 'Another owner', 'contact_details' => 'another@example.test']);
        $other = User::factory()->create(); $other->person()->associate($otherPerson)->save();
        Sanctum::actingAs($other, ['user']);
        $this->getJson('/api/my/finder-reports/'.$report)->assertNotFound();
        $this->user();
        $this->postJson('/api/my/finder-reports/'.$report.'/withdraw', [])->assertOk()->assertJsonPath('data.status', 'WITHDRAWN');
        $this->staff();
        $this->postJson('/api/staff/finder-reports/'.$report.'/receive', ['category_id' => $this->category->category_id, 'initial_location_id' => $this->location->location_id])->assertConflict();
        $this->user();
        $new = $this->postJson('/api/my/finder-reports', [
            'item_description' => 'A reported red scarf', 'found_place' => 'Campus hall', 'found_at' => '2026-10-01T10:30:00+08:00',
        ])->assertCreated();
        $report2 = $new->json('data.report_id');
        $this->staff();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-01 10:00:00.000000', 'Asia/Manila'));
        $this->postJson('/api/staff/finder-reports/'.$report2.'/receive', [
            'category_id' => $this->category->category_id, 'initial_location_id' => $this->location->location_id,
        ])->assertUnprocessable();
        $this->assertDatabaseHas('finder_report', ['report_id' => $report2, 'status' => 'PENDING', 'received_item_id' => null]);
        $this->assertSame($before, DB::table('found_item')->count());
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-01 12:05:00.654321', 'Asia/Manila'));
        $response = $this->postJson('/api/staff/finder-reports/'.$report2.'/receive', [
            'category_id' => $this->category->category_id, 'initial_location_id' => $this->location->location_id,
        ])->assertCreated()->assertJsonPath('data.status', 'RECEIVED');
        $receivedItemId = $response->json('data.item_id');
        $this->postJson('/api/staff/finder-reports/'.$report2.'/receive', ['category_id' => $this->category->category_id, 'initial_location_id' => $this->location->location_id])->assertConflict();
        $this->assertSame($before + 1, DB::table('found_item')->count());
        $this->assertDatabaseHas('finder_report', ['report_id' => $report2, 'status' => 'RECEIVED', 'received_item_id' => $receivedItemId]);
        $this->user();
        $this->getJson('/api/my/finder-reports/'.$report2)->assertOk()->assertJsonPath('data.received_item_id', $receivedItemId);
    }

    public function test_user_report_event_times_store_and_return_as_manila_local_values(): void
    {
        $this->user();
        $foundAt = '2026-10-01 09:30:00.000000';
        $finder = $this->postJson('/api/my/finder-reports', [
            'item_description' => 'Timezone fixture found item', 'found_place' => 'Test lobby',
            'found_at' => '2026-10-01T09:30:00+08:00',
        ])->assertCreated()->assertJsonPath('data.found_at', $foundAt);
        $this->assertDatabaseHas('finder_report', ['report_id' => $finder->json('data.report_id'), 'found_at' => $foundAt]);

        $lostAt = '2026-09-30 18:15:00.000000';
        $missing = $this->postJson('/api/my/missing-reports', [
            'item_description' => 'Timezone fixture missing item', 'last_known_location' => 'Test hallway',
            'lost_at' => '2026-09-30T18:15:00+08:00',
        ])->assertCreated()->assertJsonPath('data.lost_at', $lostAt);
        $this->assertDatabaseHas('missing_item_report', ['report_id' => $missing->json('data.report_id'), 'lost_at' => $lostAt]);
    }

    public function test_missing_reports_are_private_editable_until_resolved_and_staff_review_does_not_return_items(): void
    {
        $this->user();
        $created = $this->postJson('/api/my/missing-reports', [
            'item_description' => 'Missing green notebook', 'category_id' => null,
            'lost_at' => '2026-09-30T15:00:00+08:00', 'last_known_location' => 'Private building and room',
        ])->assertCreated()->assertJsonPath('data.status', 'OPEN');
        $id = $created->json('data.report_id');
        $this->patchJson('/api/my/missing-reports/'.$id, ['last_known_location' => 'Updated private place'])->assertOk();
        $otherPerson = Person::create(['full_name' => 'Not the owner', 'contact_details' => 'no@example.test']);
        $other = User::factory()->create(); $other->person()->associate($otherPerson)->save();
        Sanctum::actingAs($other, ['user']);
        $this->getJson('/api/my/missing-reports/'.$id)->assertNotFound();
        $this->user();
        $this->postJson('/api/my/missing-reports/'.$id.'/resolve', [])->assertOk()->assertJsonPath('data.status', 'RESOLVED');
        $this->patchJson('/api/my/missing-reports/'.$id, ['item_description' => 'must not update'])->assertConflict();
        $this->staff();
        $this->getJson('/api/staff/missing-reports?q=notebook')->assertOk()->assertJsonPath('data.0.reporter_email', 'owner@example.test');
        $this->postJson('/api/staff/missing-reports/'.$id.'/review', [])->assertOk()->assertJsonPath('data.status', 'RESOLVED');
        $this->assertDatabaseHas('missing_item_report', ['report_id' => $id, 'status' => 'RESOLVED', 'reviewed_by' => $this->staffUser->staff_id]);
        $this->assertDatabaseMissing('claim', ['item_id' => $this->itemId, 'outcome' => 'RETURNED']);
        $this->assertDatabaseHas('storage_assignment', ['item_id' => $this->itemId, 'removed_at' => null]);
    }

    public function test_password_recovery_and_deactivated_user_access(): void
    {
        $this->postJson('/api/auth/password/forgot', ['email' => $this->user->email])->assertOk();
        $mail = Notification::sent($this->user, ResetUserPassword::class)->last()->toMail($this->user);
        $this->assertSame(1, preg_match('/\b[0-9]{6}\b/', implode(' ', $mail->introLines), $matches));
        $code = $matches[0];
        $authorization = $this->postJson('/api/auth/password/verify-code', [
            'email' => $this->user->email, 'code' => $code,
        ])->assertOk()->json('data.authorization');
        $this->postJson('/api/auth/password/reset', [
            'email' => $this->user->email, 'authorization' => $authorization,
            'password' => 'A-new-password-12345', 'password_confirmation' => 'A-new-password-12345',
        ])->assertOk();
        $this->assertTrue(password_verify('A-new-password-12345', $this->user->fresh()->password));
        $this->postJson('/api/auth/password/forgot', ['email' => $this->user->email])->assertOk();
        Notification::assertSentTo($this->user, ResetUserPassword::class);
        $this->user->deactivated_at = now(); $this->user->save();
        Sanctum::actingAs($this->user, ['user']);
        $this->getJson('/api/my/finder-reports')->assertForbidden();
    }
}
