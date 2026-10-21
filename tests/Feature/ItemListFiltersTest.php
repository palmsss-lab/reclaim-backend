<?php

namespace Tests\Feature;

use App\Actions\Items\ReceiveFoundItem;
use App\Actions\Items\ReturnFoundItem;
use App\Actions\Items\TransferFoundItem;
use App\Models\Category;
use App\Models\StorageLocation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ReviewFixtures;
use Tests\TestCase;

class ItemListFiltersTest extends TestCase
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

    public function test_default_list_keeps_its_response_and_returns_stored_items_only(): void
    {
        $returnedId = $this->returnFixtureItem();
        $response = $this->getJson('/api/items')->assertOk()->assertJsonPath('total', 0);
        $this->assertSame(['current_page', 'data', 'first_page_url', 'from', 'last_page', 'last_page_url', 'links', 'next_page_url', 'path', 'per_page', 'prev_page_url', 'to', 'total'], array_keys($response->json()));
        $this->assertDatabaseHas('found_item', ['item_id' => $returnedId]);

        $storedId = $this->createItem('Fictional stored wallet');
        $response = $this->getJson('/api/items')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.item_id', $storedId)
            ->assertJsonPath('data.0.current_location_id', $this->location->location_id)
            ->assertJsonPath('data.0.current_location_code', $this->location->location_code);
        $this->assertSame([
            'item_id', 'finder_id', 'category_id', 'category_name', 'item_description', 'found_place',
            'found_at', 'received_at', 'current_location_id', 'current_location_code',
        ], array_keys($response->json('data.0')));
    }

    public function test_description_category_location_and_combined_filters_use_current_assignment(): void
    {
        $storedId = $this->createItem('Fictional amber wallet');
        $otherCategory = Category::create(['category_name' => 'Other list category']);
        $otherLocation = StorageLocation::create(['location_code' => 'LIST-OTHER', 'location_description' => 'Fictional other shelf']);
        $otherId = $this->createItem('Fictional blue bag', $otherCategory->category_id);

        $this->getJson('/api/items?q=amber')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.item_id', $storedId);
        $this->getJson('/api/items?q=%25')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/items?category_id='.$otherCategory->category_id)->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.item_id', $otherId);
        $this->getJson('/api/items?location_id='.$this->location->location_id)->assertOk()->assertJsonPath('total', 3);
        $this->getJson('/api/items?q=amber&category_id='.$this->category->category_id.'&location_id='.$this->location->location_id.'&status=stored')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.item_id', $storedId);
        $this->getJson('/api/items?location_id='.$otherLocation->location_id)->assertOk()->assertJsonPath('total', 0);

        $this->setClock('12:10:00.100001');
        app(TransferFoundItem::class)->execute($storedId, $otherLocation->location_id, $this->staffUser->staff_id);
        $this->getJson('/api/items?location_id='.$this->location->location_id)->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/items?location_id='.$otherLocation->location_id)->assertOk()->assertJsonPath('total', 1);
    }

    public function test_status_filters_derive_returned_state_and_clear_current_location(): void
    {
        $returnedId = $this->returnFixtureItem();
        $storedId = $this->createItem('Fictional still stored');

        $this->getJson('/api/items?status=stored')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.item_id', $storedId);
        $this->getJson('/api/items?status=returned')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.item_id', $returnedId)
            ->assertJsonPath('data.0.current_location_id', null)
            ->assertJsonPath('data.0.current_location_code', null);
        $this->getJson('/api/items?status=all')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/items?status=returned&location_id='.$this->location->location_id)->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/items?q=no-such-description')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_receipt_date_range_is_inclusive_and_filters_boundaries(): void
    {
        $previousId = $this->createItem('Fictional previous date', null, '2026-09-29');
        $inRangeId = $this->item->item_id;
        $lastDayId = $this->createItem('Fictional last-day microsecond', null, '2026-09-30', '23:59:59.999999');
        $nextId = $this->createItem('Fictional next date', null, '2026-10-01');

        $response = $this->getJson('/api/items?received_from=2026-09-29&received_to=2026-09-30&status=all')->assertOk();
        $this->assertSame(3, $response->json('total'));
        $this->assertEqualsCanonicalizing([$previousId, $inRangeId, $lastDayId], array_column($response->json('data'), 'item_id'));
        $this->getJson('/api/items?received_to=2026-09-30')->assertOk()->assertJsonPath('total', 3)->assertJsonPath('data.0.item_id', $lastDayId);
        $this->getJson('/api/items?received_from=2026-10-01')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.item_id', $nextId);
    }

    public function test_pagination_and_allowlisted_sorting_have_stable_order(): void
    {
        $first = $this->createItem('Fictional page first');
        $second = $this->createItem('Fictional page second');
        $ascending = $this->getJson('/api/items?sort_by=item_id&sort_direction=asc&per_page=1&page=1')->assertOk();
        $ascending->assertJsonPath('total', 3)->assertJsonPath('data.0.item_id', min($this->item->item_id, $first, $second));
        $secondPage = $this->getJson('/api/items?q=Fictional&sort_by=item_id&sort_direction=asc&per_page=1&page=2')->assertOk()
            ->assertJsonPath('data.0.item_id', collect([$this->item->item_id, $first, $second])->sort()->values()[1]);
        $this->assertStringContainsString('q=Fictional', $secondPage->json('first_page_url'));
        $this->getJson('/api/items?sort_by=description&sort_direction=asc')->assertOk()->assertJsonPath('data.0.item_description', 'Fictional page first');
        $this->getJson('/api/items?sort_by=received_at')->assertOk();
    }

    public function test_invalid_filters_dates_pagination_and_sorting_do_not_change_records(): void
    {
        $before = $this->domainSnapshot();
        $this->getJson('/api/items?category_id=bad&location_id=bad&status=missing')->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id', 'location_id', 'status']);
        $this->getJson('/api/items?received_from=2026-02-30&received_to=2026-02-28')->assertUnprocessable()
            ->assertJsonValidationErrors(['received_from']);
        $this->getJson('/api/items?received_from=2026-10-01&received_to=2026-09-30')->assertUnprocessable()
            ->assertJsonValidationErrors(['received_to']);
        $this->getJson('/api/items?q='.str_repeat('x', 201))->assertUnprocessable()->assertJsonValidationErrors('q');
        $this->getJson('/api/items?per_page=0&page=0&sort_by=unknown')->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page', 'page', 'sort_by']);
        $this->getJson('/api/items?sort_direction=asc')->assertUnprocessable()->assertJsonValidationErrors('sort_by');
        $this->assertSame($before, $this->domainSnapshot());
    }

    public function test_list_authorization_rejects_anonymous_unlinked_and_limited_accounts_without_changes(): void
    {
        $before = $this->domainSnapshot();
        auth()->forgetGuards();
        auth()->shouldUse('web');
        $this->getJson('/api/items?status=all')->assertUnauthorized();

        $unlinked = User::factory()->create();
        $this->getJson('/api/items?status=all', ['Authorization' => 'Bearer '.$unlinked->createToken('unlinked', ['staff'])->plainTextToken])->assertForbidden();
        $this->getJson('/api/items?status=all', ['Authorization' => 'Bearer '.$this->staffUser->createToken('limited', ['read-only'])->plainTextToken])->assertForbidden();
        $this->assertSame($before, $this->domainSnapshot());
    }

    private function createItem(string $description, ?int $categoryId = null, string $date = '2026-09-30', string $time = '10:00:00.123456'): int
    {
        $foundAt = $date.'T08:00:00+08:00';
        CarbonImmutable::setTestNow(CarbonImmutable::parse($date.' '.$time, 'Asia/Manila'));
        $item = app(ReceiveFoundItem::class)->execute([
            'finder_id' => $this->people[0]->person_id,
            'category_id' => $categoryId ?? $this->category->category_id,
            'initial_location_id' => $this->location->location_id,
            'item_description' => $description,
            'found_place' => 'Fictional test library',
            'found_at' => $foundAt,
        ], $this->staffUser->staff_id);
        $this->setClock('12:00:00.654321');

        return $item->item_id;
    }

    private function returnFixtureItem(): int
    {
        $this->setClock('12:00:00.654321');
        app(ReturnFoundItem::class)->execute($this->item->item_id, $this->people[0]->person_id, $this->staffUser->staff_id);

        return $this->item->item_id;
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
