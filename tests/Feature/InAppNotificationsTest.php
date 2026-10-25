<?php

namespace Tests\Feature;

use App\Actions\Claims\SubmitClaim;
use App\Actions\Items\ReceiveFoundItem;
use App\Models\Category;
use App\Models\Person;
use App\Models\Staff;
use App\Models\StorageLocation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InAppNotificationsTest extends TestCase
{
    private User $owner;
    private User $other;
    private User $staff;
    private int $item;
    private int $missing;
    private int $finder;
    private int $category;
    private int $location;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('127.0.0.1', DB::connection()->getConfig('host'));
        $this->assertSame(3307, (int) DB::connection()->getConfig('port'));
        $this->assertSame('reclaim_test_claims', DB::selectOne('SELECT DATABASE() AS name')->name);
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function (): void { while (DB::transactionLevel() > 0) DB::rollBack(); CarbonImmutable::setTestNow(); });
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-24 10:00:00', 'Asia/Manila'));
        $this->owner = $this->account('Notification owner');
        $this->other = $this->account('Other notification owner');
        $staff = Staff::create(['full_name' => 'Notification staff']);
        $this->staff = User::factory()->create(['staff_id' => $staff->staff_id]);
        $this->category = Category::create(['category_name' => 'Notification fixtures'])->category_id;
        $this->location = StorageLocation::create(['location_code' => 'PRIVATE-NOTIFY-CABINET', 'location_description' => 'Private storage sentinel'])->location_id;
        $this->item = app(ReceiveFoundItem::class)->execute([
            'finder_id' => $this->owner->person_id, 'category_id' => $this->category, 'initial_location_id' => $this->location,
            'item_description' => 'PRIVATE-INTERNAL-SENTINEL', 'found_place' => 'PRIVATE-PLACE-SENTINEL', 'found_at' => '2026-10-24T09:00:00+08:00',
        ], $staff->staff_id)->item_id;
        Sanctum::actingAs($this->owner, ['user']);
        $this->missing = $this->postJson('/api/my/missing-reports', ['item_description' => 'PRIVATE-MISSING-SENTINEL', 'last_known_location' => 'PRIVATE-LOST-PLACE', 'lost_at' => '2026-10-23T12:00:00+08:00'])->assertCreated()->json('data.report_id');
        $this->finder = $this->postJson('/api/my/finder-reports', ['item_description' => 'PRIVATE-FINDER-SENTINEL', 'found_place' => 'Private report place', 'found_at' => '2026-10-24T09:00:00+08:00'])->assertCreated()->json('data.report_id');
        Sanctum::actingAs($this->staff, ['staff']);
        $this->putJson('/api/staff/items/'.$this->item.'/public-listing', ['public_description' => 'A public bag'])->assertCreated();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-24 11:00:00', 'Asia/Manila'));
    }

    private function account(string $name): User
    {
        $person = Person::create(['full_name' => $name, 'contact_details' => 'PRIVATE-CONTACT-SENTINEL']);
        return User::factory()->create(['person_id' => $person->person_id]);
    }

    private function matchUrl(): string { return '/api/staff/missing-reports/'.$this->missing.'/possible-matches'; }
    private function sendMatch(): void { $this->postJson($this->matchUrl(), ['item_id' => $this->item])->assertOk(); }
    private function own(): void { Sanctum::actingAs($this->owner, ['user']); }

    private function reminderUrl(): string { return '/api/staff/finder-reports/'.$this->finder.'/request-handover'; }
    private function reminderBody(string $suffix = '1'): array { return ['request_id' => '00000000-0000-4000-8000-'.str_pad($suffix, 12, '0', STR_PAD_LEFT)]; }

    public function test_handover_reminder_is_private_and_preserves_report_item_claim_and_storage_state(): void
    {
        $before = [];
        foreach (['finder_report', 'found_item', 'claim', 'storage_assignment'] as $table) $before[$table] = DB::table($table)->get()->toJson();
        $this->postJson($this->reminderUrl(), $this->reminderBody())->assertOk()->assertJsonPath('data.deduplicated', false);
        foreach ($before as $table => $rows) $this->assertSame($rows, DB::table($table)->get()->toJson(), $table);
        $this->getJson('/api/staff/finder-reports/'.$this->finder)->assertJsonPath('data.last_handover_reminder_at', '2026-10-24 11:00:00.000000');
        $this->own();
        $response = $this->getJson('/api/my/notifications')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kind', 'finder_handover_requested')->assertJsonPath('data.0.target_type', 'finder_report')
            ->assertJsonPath('data.0.target_id', $this->finder)->assertJsonPath('data.0.possible_item_id', null);
        $this->assertStringContainsString('have not received', $response->json('data.0.message'));
        $this->assertStringContainsString('bring it to the office for physical handover', $response->json('data.0.message'));
        foreach (['PRIVATE-', 'user_id', 'event_key', 'contact_details', 'storage_location', 'evidence_text'] as $secret) $response->assertDontSee($secret);
        $id = $response->json('data.0.notification_id');
        Sanctum::actingAs($this->other, ['user']);
        $this->getJson('/api/my/notifications')->assertJsonCount(0, 'data');
        $this->patchJson('/api/my/notifications/'.$id.'/read')->assertNotFound();
    }

    public function test_handover_retry_preserves_read_state_and_later_intentional_reminder_is_allowed_at_one_hour(): void
    {
        $this->postJson($this->reminderUrl(), $this->reminderBody())->assertOk();
        $this->own(); $this->patchJson('/api/my/notifications/read-all')->assertOk();
        $before = DB::table('in_app_notification')->get()->toJson();
        Sanctum::actingAs($this->staff, ['staff']);
        $this->postJson($this->reminderUrl(), $this->reminderBody())->assertOk()->assertJsonPath('data.deduplicated', true);
        $this->postJson($this->reminderUrl(), $this->reminderBody('2'))->assertConflict();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour()->subMicrosecond());
        $this->postJson($this->reminderUrl(), $this->reminderBody('2'))->assertConflict();
        $this->assertSame($before, DB::table('in_app_notification')->get()->toJson());
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMicrosecond());
        $this->postJson($this->reminderUrl(), $this->reminderBody('2'))->assertOk()->assertJsonPath('data.deduplicated', false);
        $this->postJson($this->reminderUrl(), $this->reminderBody())->assertOk()->assertJsonPath('data.deduplicated', true);
        $this->assertDatabaseCount('in_app_notification', 2);
        $this->own(); $this->getJson('/api/my/notifications/unread-count')->assertJsonPath('unread_count', 1);
    }

    public function test_handover_requires_staff_and_valid_request_key_and_never_accepts_recipient_or_message(): void
    {
        $this->own(); $this->postJson($this->reminderUrl(), $this->reminderBody())->assertForbidden();
        Sanctum::actingAs($this->staff, ['user']); $this->postJson($this->reminderUrl(), $this->reminderBody())->assertForbidden();
        auth()->forgetGuards(); auth()->shouldUse('web'); $this->postJson($this->reminderUrl(), $this->reminderBody())->assertUnauthorized();
        Sanctum::actingAs($this->staff, ['staff']);
        $this->postJson($this->reminderUrl())->assertUnprocessable()->assertJsonValidationErrors('request_id');
        $this->postJson($this->reminderUrl(), ['request_id' => 'invalid'])->assertUnprocessable();
        $fields = ['user_id', 'recipient_id', 'recipient_user_id', 'person_id', 'staff_id', 'message', 'status', 'item_id'];
        $this->postJson($this->reminderUrl(), $this->reminderBody() + array_fill_keys($fields, '1'))->assertUnprocessable()->assertJsonValidationErrors($fields);
        $this->assertDatabaseCount('in_app_notification', 0);
    }

    public function test_handover_reminders_and_retries_reject_every_nonpending_state_and_missing_report(): void
    {
        $this->postJson($this->reminderUrl(), $this->reminderBody())->assertOk();
        foreach (['CLOSED', 'WITHDRAWN', 'RECEIVED'] as $status) {
            $reviewed = $status !== 'WITHDRAWN';
            DB::table('finder_report')->where('report_id', $this->finder)->update([
                'status' => $status, 'reviewed_by' => $reviewed ? $this->staff->staff_id : null,
                'reviewed_at' => $reviewed ? '2026-10-24 11:00:00' : null,
                'received_item_id' => $status === 'RECEIVED' ? $this->item : null,
            ]);
            $this->postJson($this->reminderUrl(), $this->reminderBody())->assertConflict();
        }
        $this->postJson('/api/staff/finder-reports/99999999/request-handover', $this->reminderBody())->assertNotFound();
        $this->assertDatabaseCount('in_app_notification', 1);
    }

    public function test_handover_reminder_rolls_back_with_outer_transaction_or_persistence_failure(): void
    {
        DB::beginTransaction();
        $this->postJson($this->reminderUrl(), $this->reminderBody())->assertOk();
        DB::rollBack(); $this->assertDatabaseCount('in_app_notification', 0);
        DB::listen(function ($query): void { if (str_contains($query->sql, 'insert into `in_app_notification`')) throw new \RuntimeException('Simulated persistence failure'); });
        $this->postJson($this->reminderUrl(), $this->reminderBody())->assertStatus(500);
        $this->assertDatabaseCount('in_app_notification', 0);
        $this->assertDatabaseHas('finder_report', ['report_id' => $this->finder, 'status' => 'PENDING', 'received_item_id' => null]);
    }

    public function test_handover_route_throttles_repeated_requests_without_duplicate_notifications(): void
    {
        for ($request = 0; $request < 10; $request++) $this->postJson($this->reminderUrl(), $this->reminderBody())->assertOk();
        $this->postJson($this->reminderUrl(), $this->reminderBody())->assertStatus(429);
        $this->assertDatabaseCount('in_app_notification', 1);
    }

    public function test_possible_match_derives_owner_is_private_and_never_creates_a_claim_or_resolves_the_report(): void
    {
        $this->sendMatch(); $this->own();
        $response = $this->getJson('/api/my/notifications')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kind', 'possible_match')->assertJsonPath('data.0.target_type', 'missing_report')
            ->assertJsonPath('data.0.target_id', $this->missing)->assertJsonPath('data.0.possible_item_id', $this->item);
        $this->assertStringContainsString('possible match', $response->json('data.0.message'));
        $this->assertStringContainsString('does not confirm ownership', $response->json('data.0.message'));
        foreach (['PRIVATE-', 'user_id', 'event_key', 'claimant_id', 'evidence_text', 'storage_location', 'contact_details'] as $secret) $response->assertDontSee($secret);
        $this->assertDatabaseCount('claim', 0);
        $this->assertDatabaseHas('missing_item_report', ['report_id' => $this->missing, 'status' => 'OPEN']);
        $this->getJson('/api/my/notifications/unread-count')->assertOk()->assertExactJson(['unread_count' => 1]);
        $this->assertStringEndsWith('+08:00', $response->json('data.0.created_at'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_read_operations_are_owner_scoped_idempotent_and_do_not_reset_on_a_retried_match(): void
    {
        $this->sendMatch(); $this->own();
        $id = $this->getJson('/api/my/notifications')->json('data.0.notification_id');
        Sanctum::actingAs($this->other, ['user']);
        $this->getJson('/api/my/notifications?user_id='.$this->owner->id)->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson('/api/my/notifications/'.$id.'/read')->assertNotFound();
        $this->patchJson('/api/my/notifications/read-all')->assertOk()->assertJsonPath('unread_count', 0);
        $this->own();
        $first = $this->patchJson('/api/my/notifications/'.$id.'/read')->assertOk()->json('data.read_at');
        $this->patchJson('/api/my/notifications/'.$id.'/read')->assertOk()->assertJsonPath('data.read_at', $first);
        Sanctum::actingAs($this->staff, ['staff']); $this->sendMatch();
        $this->own();
        $this->getJson('/api/my/notifications')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.read_at', $first);
        $this->getJson('/api/my/notifications/unread-count')->assertOk()->assertJsonPath('unread_count', 0);
    }

    public function test_staff_and_anonymous_accounts_cannot_read_user_notifications_and_users_cannot_send_matches(): void
    {
        $this->getJson('/api/my/notifications')->assertForbidden();
        $this->own(); $this->postJson($this->matchUrl(), ['item_id' => $this->item])->assertForbidden();
        Sanctum::actingAs($this->staff, ['user']); $this->postJson($this->matchUrl(), ['item_id' => $this->item])->assertForbidden();
        auth()->forgetGuards(); auth()->shouldUse('web');
        $this->getJson('/api/my/notifications')->assertUnauthorized();
        $this->getJson('/api/my/notifications/unread-count')->assertUnauthorized();
        $this->patchJson('/api/my/notifications/read-all')->assertUnauthorized();
        $this->postJson($this->matchUrl(), ['item_id' => $this->item])->assertUnauthorized();
    }

    public function test_recipient_and_message_cannot_be_supplied_and_only_open_reports_with_public_stored_items_can_be_matched(): void
    {
        $this->postJson($this->matchUrl(), ['item_id' => $this->item, 'user_id' => $this->other->id, 'recipient_id' => $this->other->id, 'message' => 'This belongs to you'])->assertUnprocessable()->assertJsonValidationErrors(['user_id', 'recipient_id', 'message']);
        $this->postJson($this->matchUrl(), ['item_id' => 99999999])->assertUnprocessable();
        $this->deleteJson('/api/staff/items/'.$this->item.'/public-listing')->assertOk();
        $this->postJson($this->matchUrl(), ['item_id' => $this->item])->assertConflict();
        $this->assertDatabaseCount('in_app_notification', 0);
        $this->putJson('/api/staff/items/'.$this->item.'/public-listing', ['public_description' => 'A public bag'])->assertOk();
        $this->own(); $this->postJson('/api/my/missing-reports/'.$this->missing.'/resolve')->assertOk();
        Sanctum::actingAs($this->staff, ['staff']);
        $this->postJson($this->matchUrl(), ['item_id' => $this->item])->assertConflict();
    }

    public function test_unpublishing_a_previously_notified_item_hides_its_reference_from_notification_responses(): void
    {
        $this->sendMatch(); $this->deleteJson('/api/staff/items/'.$this->item.'/public-listing')->assertOk();
        $this->own(); $this->getJson('/api/my/notifications')->assertOk()->assertJsonPath('data.0.possible_item_id', null);
    }

    public function test_missing_report_review_notifies_once_without_changing_owner_only_status_permissions(): void
    {
        $this->postJson('/api/staff/missing-reports/'.$this->missing.'/review')->assertOk();
        $this->postJson('/api/staff/missing-reports/'.$this->missing.'/review')->assertOk();
        $this->own();
        $this->getJson('/api/my/notifications')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.kind', 'missing_reviewed');
        $this->assertDatabaseHas('missing_item_report', ['report_id' => $this->missing, 'status' => 'OPEN']);
        $this->postJson('/api/staff/missing-reports/'.$this->missing.'/review')->assertForbidden();
    }

    public function test_finder_receipt_and_closure_notify_only_the_owner_and_retries_do_not_duplicate(): void
    {
        $this->postJson('/api/staff/finder-reports/'.$this->finder.'/receive', ['category_id' => $this->category, 'initial_location_id' => $this->location])->assertCreated();
        $this->postJson('/api/staff/finder-reports/'.$this->finder.'/receive', ['category_id' => $this->category, 'initial_location_id' => $this->location])->assertConflict();
        $this->own(); $second = $this->postJson('/api/my/finder-reports', ['item_description' => 'Second fixture', 'found_place' => 'Desk', 'found_at' => '2026-10-24T09:00:00+08:00'])->assertCreated()->json('data.report_id');
        Sanctum::actingAs($this->staff, ['staff']);
        $this->postJson('/api/staff/finder-reports/'.$second.'/close')->assertOk();
        $this->postJson('/api/staff/finder-reports/'.$second.'/close')->assertConflict();
        $this->own(); $this->getJson('/api/my/notifications')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/my/notifications/unread-count')->assertOk()->assertJsonPath('unread_count', 2);
        $this->patchJson('/api/my/notifications/read-all')->assertOk()->assertJsonPath('unread_count', 0);
        $this->patchJson('/api/my/notifications/read-all')->assertOk()->assertJsonPath('unread_count', 0);
    }

    public function test_return_notifies_each_account_claimant_of_only_their_own_final_outcome(): void
    {
        foreach ([$this->owner, $this->other] as $account) app(SubmitClaim::class)->execute($this->item, ['claimant_id' => $account->person_id, 'evidence_text' => 'PRIVATE-CLAIM-SENTINEL']);
        $this->postJson('/api/items/'.$this->item.'/claims/'.$this->owner->person_id.'/return')->assertOk();
        $this->postJson('/api/items/'.$this->item.'/claims/'.$this->owner->person_id.'/return')->assertConflict();
        $this->own(); $this->getJson('/api/my/notifications')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.kind', 'claim_returned')->assertDontSee('PRIVATE-');
        Sanctum::actingAs($this->other, ['user']);
        $this->getJson('/api/my/notifications')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.kind', 'claim_rejected')->assertDontSee('PRIVATE-');
    }

    public function test_rejection_notifies_the_exact_account_and_does_not_notify_walk_in_claimants(): void
    {
        $walkIn = Person::create(['full_name' => 'No account claimant', 'contact_details' => 'Private walk-in contact']);
        foreach ([$this->owner->person_id, $walkIn->person_id] as $person) app(SubmitClaim::class)->execute($this->item, ['claimant_id' => $person, 'evidence_text' => 'Private evidence']);
        foreach ([$this->owner->person_id, $walkIn->person_id] as $person) $this->postJson('/api/items/'.$this->item.'/claims/'.$person.'/reject')->assertOk();
        $this->postJson('/api/items/'.$this->item.'/claims/'.$this->owner->person_id.'/reject')->assertConflict();
        $this->assertDatabaseCount('in_app_notification', 1);
        $this->own(); $this->getJson('/api/my/notifications')->assertOk()->assertJsonPath('data.0.target_type', 'claim')->assertJsonPath('data.0.target_id', $this->item);
    }

    public function test_failed_notification_insert_rolls_back_the_staff_review_and_does_not_emit_a_notification(): void
    {
        app(SubmitClaim::class)->execute($this->item, ['claimant_id' => $this->owner->person_id, 'evidence_text' => 'Private evidence']);
        DB::listen(function ($query): void { if (str_contains($query->sql, 'insert into `in_app_notification`')) throw new \RuntimeException('Simulated persistence failure'); });
        $this->postJson('/api/items/'.$this->item.'/claims/'.$this->owner->person_id.'/reject')->assertStatus(500);
        $this->assertDatabaseHas('claim', ['item_id' => $this->item, 'claimant_id' => $this->owner->person_id, 'outcome' => 'PENDING', 'reviewed_at' => null]);
        $this->assertDatabaseCount('in_app_notification', 0);
    }

    public function test_outer_rollback_removes_possible_match_and_report_status_notifications(): void
    {
        DB::beginTransaction(); $this->sendMatch();
        $this->postJson('/api/staff/finder-reports/'.$this->finder.'/close')->assertOk();
        $this->assertDatabaseCount('in_app_notification', 2); DB::rollBack();
        $this->assertDatabaseCount('in_app_notification', 0);
        $this->assertDatabaseHas('finder_report', ['report_id' => $this->finder, 'status' => 'PENDING']);
    }

    public function test_notification_lists_validate_pagination_and_allow_authenticated_unverified_owners_to_read_their_updates(): void
    {
        $this->sendMatch(); $this->own();
        $this->getJson('/api/my/notifications?per_page=0')->assertUnprocessable();
        $this->getJson('/api/my/notifications?page=0')->assertUnprocessable();
        $this->owner->forceFill(['email_verified_at' => null])->save(); $this->own();
        $this->getJson('/api/my/notifications')->assertOk()->assertJsonCount(1, 'data');
    }
}
