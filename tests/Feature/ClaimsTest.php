<?php

namespace Tests\Feature;

use App\Actions\Items\ReceiveFoundItem;
use App\Actions\Items\TransferFoundItem;
use App\Models\Category;
use App\Models\FoundItem;
use App\Models\Person;
use App\Models\Staff;
use App\Models\StorageLocation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClaimsTest extends TestCase
{
    private User $staffUser;

    private Person $claimant;

    private FoundItem $item;

    private StorageLocation $location;

    protected function setUp(): void
    {
        parent::setUp();

        // Fail closed before any fixture writes, including when config is cached.
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('reclaim_test_claims', DB::selectOne('SELECT DATABASE() AS name')->name);
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function (): void {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        });

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-30 10:00:00.123456', 'Asia/Manila'));
        $this->beforeApplicationDestroyed(fn () => CarbonImmutable::setTestNow());

        $staff = Staff::create(['full_name' => 'Fictional Staff']);
        $this->staffUser = User::factory()->create();
        $this->staffUser->staff()->associate($staff)->save();
        $this->claimant = Person::create(['full_name' => 'Fictional Claimant', 'contact_details' => 'claimant@example.test']);
        $category = Category::create(['category_name' => 'Test Wallets']);
        $this->location = StorageLocation::create(['location_code' => 'TEST-A', 'location_description' => 'Fictional cabinet']);
        $this->item = app(ReceiveFoundItem::class)->execute([
            'finder_id' => $this->claimant->person_id,
            'category_id' => $category->category_id,
            'initial_location_id' => $this->location->location_id,
            'item_description' => 'Fictional brown wallet',
            'found_place' => 'Test classroom',
            'found_at' => '2026-09-30T08:00:00+08:00',
        ], $staff->staff_id);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-30 11:00:00.654321', 'Asia/Manila'));
    }

    private function url(?int $itemId = null): string
    {
        return '/api/items/'.($itemId ?? $this->item->item_id).'/claims';
    }

    private function payload(): array
    {
        return ['claimant_id' => $this->claimant->person_id, 'evidence_text' => '  Initials JM inside the wallet.  '];
    }

    private function authenticate(): void
    {
        Sanctum::actingAs($this->staffUser, ['staff']);
    }

    public function test_staff_can_submit_pending_claim_with_local_microsecond_timestamp(): void
    {
        // Exercise a real persisted Bearer token, as used by Postman.
        $token = $this->staffUser->createToken('test', ['staff'])->plainTextToken;
        $this->withToken($token)->postJson($this->url(), $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.item_id', $this->item->item_id)
            ->assertJsonPath('data.claimant_id', $this->claimant->person_id)
            ->assertJsonPath('data.evidence_text', 'Initials JM inside the wallet.')
            ->assertJsonPath('data.submitted_at', '2026-09-30T11:00:00.654321+08:00')
            ->assertJsonPath('data.outcome', 'PENDING')
            ->assertJsonPath('data.reviewed_by', null)
            ->assertJsonPath('data.reviewed_at', null);

        $this->assertDatabaseHas('claim', [
            'item_id' => $this->item->item_id,
            'claimant_id' => $this->claimant->person_id,
            'submitted_at' => '2026-09-30 11:00:00.654321',
            'outcome' => 'PENDING',
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);
        $this->assertDatabaseCount('storage_assignment', 1);
        $this->assertDatabaseHas('storage_assignment', ['item_id' => $this->item->item_id, 'removed_at' => null]);
    }

    public function test_anonymous_requests_are_unauthorized(): void
    {
        $this->postJson($this->url(), $this->payload())->assertUnauthorized();
        $this->getJson($this->url())->assertUnauthorized();
        $this->assertDatabaseCount('claim', 0);
    }

    public function test_unlinked_account_cannot_submit_or_read_evidence(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['staff']);
        $this->postJson($this->url(), $this->payload())->assertForbidden();
        $this->getJson($this->url())->assertForbidden();
        $this->assertDatabaseCount('claim', 0);
    }

    public function test_staff_token_without_staff_ability_cannot_manage_claims(): void
    {
        Sanctum::actingAs($this->staffUser, ['read-only']);
        $this->postJson($this->url(), $this->payload())->assertForbidden();
        $this->getJson($this->url())->assertForbidden();
    }

    public function test_blank_evidence_and_missing_claimant_are_rejected(): void
    {
        $this->authenticate();
        $this->postJson($this->url(), ['claimant_id' => $this->claimant->person_id, 'evidence_text' => " \t\n "])
            ->assertUnprocessable()->assertJsonValidationErrors('evidence_text');
        $this->postJson($this->url(), ['evidence_text' => 'Test evidence'])
            ->assertUnprocessable()->assertJsonValidationErrors('claimant_id');
        $this->postJson($this->url(), ['claimant_id' => 999999999, 'evidence_text' => 'Test evidence'])
            ->assertUnprocessable()->assertJsonValidationErrors('claimant_id');
        $this->assertDatabaseCount('claim', 0);
    }

    public function test_client_cannot_set_submission_or_review_fields(): void
    {
        $this->authenticate();
        $this->postJson($this->url(), array_merge($this->payload(), [
            'submitted_at' => '2026-09-30T09:00:00+08:00',
            'outcome' => 'RETURNED',
            'reviewed_by' => $this->staffUser->staff_id,
            'reviewed_at' => '2026-09-30T11:00:00+08:00',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['submitted_at', 'outcome', 'reviewed_by', 'reviewed_at']);
        $this->assertDatabaseCount('claim', 0);
    }

    public function test_missing_item_returns_404(): void
    {
        $this->authenticate();
        $this->postJson($this->url(999999999), $this->payload())->assertNotFound();
        $this->getJson($this->url(999999999))->assertNotFound();
    }

    public function test_duplicate_claim_is_conflict_and_preserves_original_evidence(): void
    {
        $this->authenticate();
        $this->postJson($this->url(), $this->payload())->assertCreated();
        $this->postJson($this->url(), array_merge($this->payload(), ['evidence_text' => 'Replacement']))
            ->assertConflict()->assertJsonPath('message', 'This person already has a claim for this item.');
        $this->assertDatabaseCount('claim', 1);
        $this->assertDatabaseHas('claim', ['evidence_text' => 'Initials JM inside the wallet.']);
    }

    public function test_rejected_claim_cannot_be_reopened(): void
    {
        $this->authenticate();
        $this->postJson($this->url(), $this->payload())->assertCreated();
        DB::table('claim')->where('item_id', $this->item->item_id)->where('claimant_id', $this->claimant->person_id)
            ->update(['outcome' => 'REJECTED', 'reviewed_by' => $this->staffUser->staff_id, 'reviewed_at' => '2026-09-30 11:30:00.000000']);
        $this->postJson($this->url(), $this->payload())->assertConflict();
        $this->assertDatabaseHas('claim', ['outcome' => 'REJECTED']);
    }

    public function test_returned_item_rejects_new_claim_without_partial_changes(): void
    {
        $this->authenticate();
        $this->postJson($this->url(), $this->payload())->assertCreated();
        DB::table('claim')->where('item_id', $this->item->item_id)->where('claimant_id', $this->claimant->person_id)
            ->update(['outcome' => 'RETURNED', 'reviewed_by' => $this->staffUser->staff_id, 'reviewed_at' => '2026-09-30 11:30:00.000000']);
        DB::table('storage_assignment')->where('item_id', $this->item->item_id)->where('assignment_no', 1)
            ->update(['removed_at' => '2026-09-30 11:30:00.000000']);
        $other = Person::create(['full_name' => 'Other Claimant', 'contact_details' => 'other@example.test']);
        $this->postJson($this->url(), ['claimant_id' => $other->person_id, 'evidence_text' => 'Evidence'])
            ->assertConflict()->assertJsonPath('message', 'A returned item cannot receive new claims.');
        $this->assertDatabaseCount('claim', 1);
        $this->assertDatabaseHas('storage_assignment', ['removed_at' => '2026-09-30 11:30:00.000000']);
    }

    public function test_submission_before_receipt_is_rejected(): void
    {
        $this->authenticate();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-30 09:00:00', 'Asia/Manila'));
        $this->postJson($this->url(), $this->payload())->assertConflict();
        $this->assertDatabaseCount('claim', 0);
    }

    public function test_claim_listing_is_paginated_filtered_and_scoped_to_item(): void
    {
        $this->authenticate();
        $this->postJson($this->url(), $this->payload())->assertCreated();
        $other = Person::create(['full_name' => 'Other Claimant', 'contact_details' => 'other@example.test']);
        $this->postJson($this->url(), ['claimant_id' => $other->person_id, 'evidence_text' => 'Other evidence'])->assertCreated();
        DB::table('claim')->where('item_id', $this->item->item_id)->where('claimant_id', $other->person_id)
            ->update(['outcome' => 'REJECTED', 'reviewed_by' => $this->staffUser->staff_id, 'reviewed_at' => '2026-09-30 11:30:00.000000']);

        $anotherItem = app(ReceiveFoundItem::class)->execute([
            'finder_id' => $this->claimant->person_id, 'category_id' => $this->item->category_id,
            'initial_location_id' => $this->location->location_id, 'item_description' => 'Another item',
            'found_place' => 'Test room', 'found_at' => '2026-09-30T08:00:00+08:00',
        ], $this->staffUser->staff_id);
        $this->postJson($this->url($anotherItem->item_id), $this->payload())->assertCreated();

        $this->getJson($this->url().'?per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.claimant_id', $this->claimant->person_id)
            ->assertJsonMissingPath('data.0.contact_details');
        $this->getJson($this->url().'?outcome=PENDING')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
        $this->getJson($this->url().'?outcome=REJECTED')->assertOk()
            ->assertJsonPath('data.0.claimant_id', $other->person_id);
        $this->getJson($this->url().'?outcome=pending&per_page=101&page=0')
            ->assertUnprocessable()->assertJsonValidationErrors(['outcome', 'per_page', 'page']);
    }

    public function test_mysql_rejects_invalid_review_fields(): void
    {
        $this->expectException(QueryException::class);
        DB::table('claim')->insert([
            'item_id' => $this->item->item_id, 'claimant_id' => $this->claimant->person_id,
            'submitted_at' => '2026-09-30 11:00:00', 'evidence_text' => 'Test evidence',
            'outcome' => 'PENDING', 'reviewed_by' => $this->staffUser->staff_id, 'reviewed_at' => null,
        ]);
    }

    public function test_mysql_preserves_composite_primary_key(): void
    {
        $this->authenticate();
        $this->postJson($this->url(), $this->payload())->assertCreated();
        $existing = (array) DB::table('claim')->where('item_id', $this->item->item_id)
            ->where('claimant_id', $this->claimant->person_id)->first();
        $this->expectException(QueryException::class);
        DB::table('claim')->insert($existing);
    }

    public function test_existing_transfer_preserves_history_after_claim_submission(): void
    {
        $this->authenticate();
        $this->postJson($this->url(), $this->payload())->assertCreated();
        $destination = StorageLocation::create(['location_code' => 'TEST-B', 'location_description' => 'Other cabinet']);
        app(TransferFoundItem::class)->execute($this->item->item_id, $destination->location_id, $this->staffUser->staff_id);
        $this->assertDatabaseHas('storage_assignment', ['item_id' => $this->item->item_id, 'assignment_no' => 1, 'removed_at' => '2026-09-30 11:00:00.654321']);
        $this->assertDatabaseHas('storage_assignment', ['item_id' => $this->item->item_id, 'assignment_no' => 2, 'removed_at' => null]);
        $this->assertDatabaseCount('claim', 1);
    }

    public function test_existing_intake_rolls_back_if_initial_placement_fails(): void
    {
        try {
            app(ReceiveFoundItem::class)->execute([
                'finder_id' => $this->claimant->person_id, 'category_id' => $this->item->category_id,
                'initial_location_id' => 999999999, 'item_description' => 'Must roll back',
                'found_place' => 'Test room', 'found_at' => '2026-09-30T08:00:00+08:00',
            ], $this->staffUser->staff_id);
            $this->fail('An invalid initial location must fail.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->errorInfo[0]);
        }
        $this->assertDatabaseCount('found_item', 1);
        $this->assertDatabaseCount('storage_assignment', 1);
    }
}
