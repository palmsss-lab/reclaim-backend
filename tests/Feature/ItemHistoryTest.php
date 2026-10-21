<?php

namespace Tests\Feature;

use App\Actions\Items\ReceiveFoundItem;
use App\Actions\Items\ReturnFoundItem;
use App\Actions\Items\TransferFoundItem;
use App\Models\Person;
use App\Models\Staff;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ReviewFixtures;
use Tests\TestCase;

class ItemHistoryTest extends TestCase
{
    use ReviewFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTestDatabase();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function (): void {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            CarbonImmutable::setTestNow();
        });
        $this->createReviewFixtures();
        Sanctum::actingAs($this->staffUser, ['staff']);
    }

    private function url(string $suffix = '', ?int $id = null): string
    {
        return '/api/items/'.($id ?? $this->item->item_id).$suffix;
    }

    private function otherItem(): int
    {
        return app(ReceiveFoundItem::class)->execute([
            'finder_id' => $this->people[3]->person_id,
            'category_id' => $this->category->category_id,
            'initial_location_id' => $this->location->location_id,
            'item_description' => 'Other fictional history item',
            'found_place' => 'Test office', 'found_at' => '2026-09-30T08:00:00+08:00',
        ], $this->staffUser->staff_id)->item_id;
    }

    private function transferTwice(): Staff
    {
        $staff = Staff::create(['full_name' => 'Fictional Transfer Staff']);
        $this->setClock('12:10:00.100001');
        app(TransferFoundItem::class)->execute($this->item->item_id, $this->destination->location_id, $staff->staff_id);
        $this->setClock('12:20:00.200002');
        app(TransferFoundItem::class)->execute($this->item->item_id, $this->location->location_id, $staff->staff_id);

        return $staff;
    }

    public function test_stored_item_details_include_related_records_and_derived_location(): void
    {
        $before = $this->domainSnapshot();
        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.item_id', $this->item->item_id)
            ->assertJsonPath('data.finder.person_id', $this->people[0]->person_id)
            ->assertJsonPath('data.finder.full_name', $this->people[0]->full_name)
            ->assertJsonPath('data.finder.contact_details', $this->people[0]->contact_details)
            ->assertJsonPath('data.category.category_id', $this->category->category_id)
            ->assertJsonPath('data.category.category_name', $this->category->category_name)
            ->assertJsonPath('data.receiving_staff.staff_id', $this->staffUser->staff_id)
            ->assertJsonPath('data.receiving_staff.full_name', 'Fictional Review Staff')
            ->assertJsonPath('data.found_at', '2026-09-30T08:00:00.000000+08:00')
            ->assertJsonPath('data.received_at', '2026-09-30T10:00:00.123456+08:00')
            ->assertJsonPath('data.is_returned', false)
            ->assertJsonPath('data.return', null)
            ->assertJsonPath('data.current_location.location_id', $this->location->location_id)
            ->assertJsonPath('data.current_location.location_description', $this->location->location_description)
            ->assertJsonPath('data.current_assignment.assignment_no', 1)
            ->assertJsonPath('data.current_assignment.assigned_at', '2026-09-30T10:00:00.123456+08:00')
            ->assertJsonPath('data.current_assignment.assigning_staff.staff_id', $this->staffUser->staff_id);
        $this->assertSame($before, $this->domainSnapshot());
    }

    public function test_multiple_transfers_preserve_order_actor_location_and_closure_dates(): void
    {
        $staff = $this->transferTwice();
        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('data.current_assignment.assignment_no', 3)
            ->assertJsonPath('data.current_assignment.assigning_staff.staff_id', $staff->staff_id)
            ->assertJsonPath('data.current_location.location_id', $this->location->location_id);
        $before = $this->domainSnapshot();
        $response = $this->getJson($this->url('/storage-history'))->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.assignment_no', 1)
            ->assertJsonPath('data.0.location.location_id', $this->location->location_id)
            ->assertJsonPath('data.0.removed_at', '2026-09-30T12:10:00.100001+08:00')
            ->assertJsonPath('data.1.assignment_no', 2)
            ->assertJsonPath('data.1.location.location_id', $this->destination->location_id)
            ->assertJsonPath('data.1.location.location_code', $this->destination->location_code)
            ->assertJsonPath('data.1.assigning_staff.full_name', $staff->full_name)
            ->assertJsonPath('data.1.assigned_at', '2026-09-30T12:10:00.100001+08:00')
            ->assertJsonPath('data.1.removed_at', '2026-09-30T12:20:00.200002+08:00')
            ->assertJsonPath('data.2.assignment_no', 3)->assertJsonPath('data.2.removed_at', null);
        $this->assertSame($this->item->item_id, $response->json('data.2.item_id'));
        $this->assertSame($before, $this->domainSnapshot());
    }

    public function test_returned_item_is_viewable_and_review_history_preserves_earlier_rejection(): void
    {
        $this->transferTwice();
        $this->setClock('12:30:00.300003');
        app(ReturnFoundItem::class)->execute($this->item->item_id, $this->people[0]->person_id, $this->staffUser->staff_id);
        $before = $this->domainSnapshot();
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.is_returned', true)
            ->assertJsonPath('data.current_location', null)->assertJsonPath('data.current_assignment', null)
            ->assertJsonPath('data.return.claimant_id', $this->people[0]->person_id)
            ->assertJsonPath('data.return.claimant.full_name', $this->people[0]->full_name)
            ->assertJsonPath('data.return.reviewer.staff_id', $this->staffUser->staff_id)
            ->assertJsonPath('data.return.reviewer.full_name', 'Fictional Review Staff')
            ->assertJsonPath('data.return.reviewed_at', '2026-09-30T12:30:00.300003+08:00');
        $this->getJson($this->url('/storage-history'))->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.2.removed_at', '2026-09-30T12:30:00.300003+08:00');
        $this->getJson($this->url('/claims'))->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.outcome', 'REJECTED')
            ->assertJsonPath('data.0.reviewed_at', '2026-09-30T11:20:00.400000+08:00')
            ->assertJsonPath('data.0.reviewer.full_name', 'Fictional Review Staff')
            ->assertJsonPath('data.0.evidence_text', 'Original evidence 2')
            ->assertJsonPath('data.1.outcome', 'REJECTED')
            ->assertJsonPath('data.1.reviewed_at', '2026-09-30T12:30:00.300003+08:00')
            ->assertJsonPath('data.2.outcome', 'RETURNED')
            ->assertJsonPath('data.2.reviewed_at', '2026-09-30T12:30:00.300003+08:00');
        $this->assertSame($before, $this->domainSnapshot());
    }

    public function test_items_without_claims_have_empty_claim_history_and_are_scoped(): void
    {
        $other = $this->otherItem();
        $this->getJson($this->url('', $other))->assertOk()->assertJsonPath('data.is_returned', false)->assertJsonPath('data.return', null);
        $this->getJson($this->url('/claims', $other))->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->getJson($this->url('/storage-history', $other))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.item_id', $other);
        $this->getJson($this->url('/claims'))->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson($this->url('/storage-history'))->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_pending_claim_review_fields_are_null_and_claimant_details_are_joined(): void
    {
        $this->getJson($this->url('/claims?outcome=PENDING'))->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.claimant.person_id', $this->people[1]->person_id)
            ->assertJsonPath('data.0.claimant.full_name', $this->people[1]->full_name)
            ->assertJsonPath('data.0.claimant.contact_details', $this->people[1]->contact_details)
            ->assertJsonPath('data.0.reviewed_by', null)->assertJsonPath('data.0.reviewed_at', null)
            ->assertJsonPath('data.0.reviewer', null);
    }

    public function test_storage_pagination_is_complete_and_retains_query_parameters(): void
    {
        $this->transferTwice();
        foreach ([1, 2, 3] as $page) {
            $response = $this->getJson($this->url('/storage-history?per_page=1&page='.$page))->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3)
                ->assertJsonPath('meta.current_page', $page)->assertJsonPath('data.0.assignment_no', $page);
            if ($page < 3) {
                $this->assertStringContainsString('per_page=1', $response->json('links.next'));
            }
        }
        $this->getJson($this->url('/storage-history?per_page=1&page=4'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_claim_pagination_uses_stable_ties_and_keeps_outcome_filter(): void
    {
        DB::table('claim')->where('item_id', $this->item->item_id)->where('outcome', 'PENDING')
            ->update(['submitted_at' => '2026-09-30 11:00:00.100000']);
        foreach ([1, 2] as $page) {
            $response = $this->getJson($this->url('/claims?outcome=PENDING&per_page=1&page='.$page))->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)
                ->assertJsonPath('data.0.claimant_id', $this->people[$page - 1]->person_id);
            $this->assertStringContainsString('outcome=PENDING', $response->json('links.first'));
        }
    }

    public static function historyPaths(): array
    {
        return ['storage' => ['/storage-history'], 'claims' => ['/claims']];
    }

    #[DataProvider('historyPaths')]
    public function test_history_pagination_rejects_invalid_values_without_changes(string $suffix): void
    {
        $before = $this->domainSnapshot();
        foreach (['per_page=0&page=0', 'per_page=101&page=-1', 'per_page=bad&page=bad'] as $query) {
            $this->getJson($this->url($suffix.'?'.$query))->assertUnprocessable()->assertJsonValidationErrors(['per_page', 'page']);
        }
        $this->assertSame($before, $this->domainSnapshot());
    }

    public static function readPaths(): array
    {
        return ['details' => [''], 'storage' => ['/storage-history'], 'claims' => ['/claims']];
    }

    #[DataProvider('readPaths')]
    public function test_missing_items_return_404(string $suffix): void
    {
        $before = $this->domainSnapshot();
        $this->getJson($this->url($suffix, 999999999))->assertNotFound();
        $this->assertSame($before, $this->domainSnapshot());
    }

    #[DataProvider('readPaths')]
    public function test_unauthorized_readers_cannot_access_details_or_history(string $suffix): void
    {
        foreach (['anonymous', 'unlinked', 'limited'] as $kind) {
            auth()->forgetGuards();
            auth()->shouldUse('web');
            $token = null;
            if ($kind !== 'anonymous') {
                $user = $kind === 'unlinked' ? User::factory()->create() : $this->staffUser;
                $token = $user->createToken('history-test', $kind === 'limited' ? ['read-only'] : ['staff'])->plainTextToken;
            }
            $before = $this->domainSnapshot();
            foreach ([$this->item->item_id, 999999999] as $id) {
                auth()->forgetGuards();
                auth()->shouldUse('web');
                $headers = $token === null ? [] : ['Authorization' => 'Bearer '.$token];
                $this->getJson($this->url($suffix, $id), $headers)->assertStatus($kind === 'anonymous' ? 401 : 403);
            }
            $this->assertSame($before, $this->domainSnapshot());
        }
    }

    #[DataProvider('readPaths')]
    public function test_authorized_web_guard_can_read_history(string $suffix): void
    {
        auth()->forgetGuards();
        auth()->shouldUse('web');
        $this->actingAs($this->staffUser->fresh(), 'web');
        $this->getJson($this->url($suffix))->assertOk();
    }

    public function test_history_query_counts_do_not_grow_with_page_size(): void
    {
        $this->transferTwice();
        foreach (range(1, 8) as $number) {
            $person = Person::create(['full_name' => 'Fictional Extra '.$number, 'contact_details' => 'extra@example.test']);
            DB::table('claim')->insert([
                'item_id' => $this->item->item_id, 'claimant_id' => $person->person_id,
                'submitted_at' => '2026-09-30 11:30:00.000000', 'evidence_text' => 'Fictional extra evidence',
                'outcome' => 'PENDING', 'reviewed_by' => null, 'reviewed_at' => null,
            ]);
        }
        foreach (['/storage-history', '/claims'] as $suffix) {
            $counts = [];
            foreach ([1, 20] as $size) {
                DB::enableQueryLog();
                DB::flushQueryLog();
                $this->getJson($this->url($suffix.'?per_page='.$size))->assertOk();
                $counts[] = count(DB::getQueryLog());
                DB::disableQueryLog();
            }
            $this->assertSame($counts[0], $counts[1], 'Related details must not cause per-row queries.');
            $this->assertLessThanOrEqual(6, $counts[1], 'The current-account activity check adds one constant query.');
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($this->url())->assertOk();
        $this->assertLessThanOrEqual(4, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    private function domainSnapshot(): array
    {
        $snapshot = [];
        foreach ([
            'person' => ['person_id'], 'staff' => ['staff_id'], 'category' => ['category_id'],
            'storage_location' => ['location_id'], 'found_item' => ['item_id'],
            'claim' => ['item_id', 'claimant_id'], 'storage_assignment' => ['item_id', 'assignment_no'],
        ] as $table => $keys) {
            $query = DB::table($table);
            foreach ($keys as $key) {
                $query->orderBy($key);
            }
            $snapshot[$table] = $query->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }
}
