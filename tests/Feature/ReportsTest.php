<?php

namespace Tests\Feature;

use App\Actions\Claims\SubmitClaim;
use App\Actions\Items\ReceiveFoundItem;
use App\Actions\Items\ReturnFoundItem;
use App\Actions\Items\TransferFoundItem;
use App\Models\Category;
use App\Models\StorageLocation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ReviewFixtures;
use Tests\TestCase;

class ReportsTest extends TestCase
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

    public function test_stored_and_category_period_reports_reuse_item_listing(): void
    {
        $previous = $this->receiveAt('2026-09-29 23:59:59.999999', 'Fictional prior item');
        $start = $this->receiveAt('2026-09-30 00:00:00.000000', 'Fictional start item');
        $last = $this->receiveAt('2026-09-30 23:59:59.999999', 'Fictional final item');
        $next = $this->receiveAt('2026-10-01 00:00:00.000000', 'Fictional next item');

        $this->getJson('/api/items?status=stored')->assertOk()->assertJsonPath('total', 5);
        $response = $this->getJson('/api/items?category_id='.$this->category->category_id.'&received_from=2026-09-30&received_to=2026-09-30&status=all&per_page=2&page=1')
            ->assertOk()->assertJsonPath('total', 3)->assertJsonPath('per_page', 2)
            ->assertJsonPath('data.0.item_id', $last)
            ->assertJsonPath('data.1.item_id', $this->item->item_id);
        $this->assertStringContainsString('received_from=2026-09-30', $response->json('first_page_url'));
        $this->getJson('/api/items?category_id='.$this->category->category_id.'&received_from=2026-09-30&received_to=2026-09-30&status=all&per_page=2&page=2')
            ->assertOk()->assertJsonPath('data.0.item_id', $start);
        $this->assertNotContains($previous, array_column($response->json('data'), 'item_id'));
        $this->assertNotContains($next, array_column($response->json('data'), 'item_id'));
    }

    public function test_pending_claim_report_is_global_filtered_and_paginated_without_history_duplication(): void
    {
        $otherItem = $this->receiveAt('2026-09-30 10:30:00.000000', 'Fictional other pending item');
        $this->setClock('11:30:00.000000');
        app(SubmitClaim::class)->execute($otherItem, [
            'claimant_id' => $this->people[3]->person_id,
            'evidence_text' => 'Fictional ownership evidence',
        ]);

        $response = $this->getJson('/api/reports/pending-claims?category_id='.$this->category->category_id.'&received_from=2026-09-30&received_to=2026-09-30&per_page=1&page=1')
            ->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('data.0.item.item_id', $otherItem)
            ->assertJsonPath('data.0.outcome', 'PENDING')
            ->assertJsonPath('data.0.claimant.person_id', $this->people[3]->person_id)
            ->assertJsonPath('data.0.item.category.category_id', $this->category->category_id);
        $this->assertStringContainsString('category_id='.$this->category->category_id, $response->json('links.first'));
        $this->getJson('/api/reports/pending-claims?per_page=1&page=2')->assertOk()
            ->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.item.item_id', $this->item->item_id);

        $emptyCategory = Category::create(['category_name' => 'Fictional empty report category']);
        $this->getJson('/api/reports/pending-claims?category_id='.$emptyCategory->category_id)->assertOk()
            ->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data');
    }

    public function test_category_return_rate_counts_items_once_and_includes_empty_categories(): void
    {
        $this->receiveAt('2026-09-30 11:00:00.000000', 'Fictional second category item');
        $emptyCategory = Category::create(['category_name' => 'Fictional category with no items']);
        $this->setClock('12:00:00.654321');
        app(ReturnFoundItem::class)->execute($this->item->item_id, $this->people[0]->person_id, $this->staffUser->staff_id);

        $response = $this->getJson('/api/reports/return-rates?received_from=2026-09-30&received_to=2026-09-30&per_page=1&page=1')
            ->assertOk()->assertJsonPath('total', 2);
        $this->assertSame(2, $response->json('data.0.received_items'));
        $this->assertSame(1, $response->json('data.0.returned_items'));
        $this->assertEquals(50, $response->json('data.0.return_rate_percent'));
        $emptyPage = $this->getJson('/api/reports/return-rates?received_from=2026-09-30&received_to=2026-09-30&per_page=1&page=2')
            ->assertOk()->assertJsonPath('total', 2)->assertJsonPath('data.0.category_id', $emptyCategory->category_id)
            ->assertJsonPath('data.0.received_items', 0)->assertJsonPath('data.0.returned_items', 0)
            ->assertJsonPath('data.0.return_rate_percent', null);
        $this->assertSame($emptyCategory->category_id, $emptyPage->json('data.0.category_id'));
    }

    public function test_storage_occupancy_uses_only_current_assignments_and_currently_stored_items(): void
    {
        $secondItem = $this->receiveAt('2026-09-30 11:00:00.000000', 'Fictional current occupancy item');
        $this->setClock('10:30:00.100000');
        app(TransferFoundItem::class)->execute($this->item->item_id, $this->destination->location_id, $this->staffUser->staff_id);
        $this->setClock('10:45:00.200000');
        app(TransferFoundItem::class)->execute($this->item->item_id, $this->location->location_id, $this->staffUser->staff_id);
        $this->setClock('11:30:00.300000');
        app(TransferFoundItem::class)->execute($secondItem, $this->destination->location_id, $this->staffUser->staff_id);
        $this->setClock('11:45:00.400000');
        app(TransferFoundItem::class)->execute($secondItem, $this->location->location_id, $this->staffUser->staff_id);
        $this->setClock('12:00:00.654321');
        app(ReturnFoundItem::class)->execute($this->item->item_id, $this->people[0]->person_id, $this->staffUser->staff_id);
        StorageLocation::create(['location_code' => 'REPORT-EMPTY', 'location_description' => 'Fictional empty report location']);

        $response = $this->getJson('/api/reports/storage-occupancy?per_page=10')->assertOk()->assertJsonPath('total', 3);
        $current = collect($response->json('data'))->firstWhere('location_id', $this->location->location_id);
        $empty = collect($response->json('data'))->firstWhere('location_id', $this->destination->location_id);
        $this->assertSame(1, $current['current_item_count']);
        $this->assertEqualsWithDelta(3600.654321 / 86400, $current['average_holding_days'], 0.000001);
        $this->assertSame(0, $empty['current_item_count']);
        $this->assertNull($empty['average_holding_days']);
        $this->assertSame('2026-09-30T12:00:00.654321+08:00', $current['measured_at']);
        $this->getJson('/api/reports/storage-occupancy?per_page=1&page=2')->assertOk()
            ->assertJsonPath('total', 3)->assertJsonPath('data.0.location_id', $this->destination->location_id)
            ->assertJsonPath('data.0.current_item_count', 0);
        $this->getJson('/api/reports/storage-occupancy?location_id='.$this->destination->location_id)->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.current_item_count', 0);
    }

    public function test_monthly_running_total_includes_pre_range_items_and_period_edges_are_inclusive(): void
    {
        $august = $this->receiveAt('2026-08-31 23:59:59.999999', 'Fictional August item');
        $septemberStart = $this->receiveAt('2026-09-01 00:00:00.000000', 'Fictional September start');
        $septemberEnd = $this->receiveAt('2026-09-30 23:59:59.999999', 'Fictional September end');
        $october = $this->receiveAt('2026-10-01 00:00:00.000000', 'Fictional October item');

        $response = $this->getJson('/api/reports/monthly-intake?category_id='.$this->category->category_id.'&received_from=2026-09-01&received_to=2026-09-30&per_page=1&page=1')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.month', '2026-09')
            ->assertJsonPath('data.0.monthly_intake', 3)->assertJsonPath('data.0.running_total', 4);
        $this->assertStringContainsString('received_to=2026-09-30', $response->json('first_page_url'));
        $this->getJson('/api/reports/monthly-intake?received_from=2026-08-01&received_to=2026-09-30&per_page=1&page=1')
            ->assertOk()->assertJsonPath('total', 2)->assertJsonPath('data.0.month', '2026-08')->assertJsonPath('data.0.running_total', 1);
        $this->getJson('/api/reports/monthly-intake?received_from=2026-08-01&received_to=2026-09-30&per_page=1&page=2')
            ->assertOk()->assertJsonPath('data.0.month', '2026-09')->assertJsonPath('data.0.monthly_intake', 3)->assertJsonPath('data.0.running_total', 4);
        $this->getJson('/api/reports/monthly-intake?received_from=2025-01-01&received_to=2025-01-31')
            ->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'data');
        $this->assertNotSame($august, $septemberStart);
        $this->assertNotSame($septemberEnd, $october);
    }

    public function test_report_filters_validate_dates_identifiers_and_pagination_without_writes(): void
    {
        $before = $this->domainSnapshot();
        $this->getJson('/api/reports/monthly-intake?received_from=2026-10-01&received_to=2026-09-30')->assertUnprocessable()
            ->assertJsonValidationErrors('received_to');
        $this->getJson('/api/reports/return-rates?received_from=2026-02-30')->assertUnprocessable()
            ->assertJsonValidationErrors('received_from');
        $this->getJson('/api/reports/pending-claims?category_id=999999999')->assertUnprocessable()
            ->assertJsonValidationErrors('category_id');
        $this->getJson('/api/reports/storage-occupancy?location_id=bad&per_page=101&page=0')->assertUnprocessable()
            ->assertJsonValidationErrors(['location_id', 'per_page', 'page']);
        $this->assertSame($before, $this->domainSnapshot());
    }

    public function test_all_reports_require_staff_authorization(): void
    {
        $paths = [
            '/api/reports/pending-claims', '/api/reports/return-rates',
            '/api/reports/storage-occupancy', '/api/reports/monthly-intake',
        ];
        $before = $this->domainSnapshot();
        auth()->forgetGuards();
        auth()->shouldUse('web');
        foreach ($paths as $path) {
            $this->getJson($path)->assertUnauthorized();
        }

        $unlinked = \App\Models\User::factory()->create();
        $unlinkedToken = $unlinked->createToken('report-unlinked', ['staff'])->plainTextToken;
        $limitedToken = $this->staffUser->createToken('report-limited', ['read-only'])->plainTextToken;
        foreach ($paths as $path) {
            $this->getJson($path, ['Authorization' => 'Bearer '.$unlinkedToken])->assertForbidden();
            $this->getJson($path, ['Authorization' => 'Bearer '.$limitedToken])->assertForbidden();
        }
        $this->assertSame($before, $this->domainSnapshot());
    }

    private function receiveAt(string $receivedAt, string $description): int
    {
        $received = CarbonImmutable::parse($receivedAt, 'Asia/Manila');
        CarbonImmutable::setTestNow($received);
        $item = app(ReceiveFoundItem::class)->execute([
            'finder_id' => $this->people[0]->person_id,
            'category_id' => $this->category->category_id,
            'initial_location_id' => $this->location->location_id,
            'item_description' => $description,
            'found_place' => 'Fictional report test place',
            'found_at' => $received->subHour()->format('Y-m-d\TH:i:s.uP'),
        ], $this->staffUser->staff_id);

        return $item->item_id;
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
