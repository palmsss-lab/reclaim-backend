<?php

namespace Tests\Feature;

use App\Actions\Items\ReceiveFoundItem;
use App\Actions\Items\TransferFoundItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ReviewFixtures;
use Tests\TestCase;

class ClassificationTabsTest extends TestCase
{
    use ReviewFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTestDatabase();
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function (): void {
            while (DB::transactionLevel()) {
                DB::rollBack();
            }
            CarbonImmutable::setTestNow();
        });
        $this->createReviewFixtures();
        Sanctum::actingAs($this->staffUser, ['staff']);
    }

    private function extraItem(string $description): int
    {
        return app(ReceiveFoundItem::class)->execute([
            'finder_id' => $this->people[0]->person_id, 'category_id' => $this->category->category_id,
            'initial_location_id' => $this->location->location_id, 'item_description' => $description,
            'found_place' => 'Private test place', 'found_at' => '2026-09-30T08:00:00+08:00',
        ], $this->staffUser->staff_id)->item_id;
    }

    private function listUrl(string $status, string $more = ''): string
    {
        return '/api/items?status='.$status.'&category_id='.$this->category->category_id.$more;
    }

    public function test_only_successful_return_moves_items_and_totals_cover_filtered_pages(): void
    {
        $none = $this->extraItem('Fictional no claims');
        $rejected = $this->extraItem('Fictional rejected only');
        $this->postJson('/api/items/'.$rejected.'/claims', ['claimant_id' => $this->people[0]->person_id, 'evidence_text' => 'Private proof'])->assertCreated();
        $this->postJson('/api/items/'.$rejected.'/claims/'.$this->people[0]->person_id.'/reject')->assertOk();
        $this->getJson($this->listUrl('stored', '&per_page=1&page=2&q=Fictional'))->assertOk()->assertJsonPath('total', 3)->assertJsonCount(1, 'data');
        $this->getJson($this->listUrl('returned'))->assertOk()->assertJsonPath('total', 0);
        $this->postJson($this->reviewUrl('return', 2))->assertConflict();
        $this->getJson($this->listUrl('stored'))->assertOk()->assertJsonPath('total', 3);
        $this->setClock('13:00:00.123456');
        $this->postJson($this->reviewUrl('return'))->assertOk();
        $stored = $this->getJson($this->listUrl('stored', '&per_page=1&page=2&q=Fictional'))->assertOk()->assertJsonPath('total', 2);
        $this->assertNotSame($this->item->item_id, $stored->json('data.0.item_id'));
        $this->getJson($this->listUrl('stored'))->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($this->listUrl('returned'))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.item_id', $this->item->item_id)->assertJsonMissingPath('data.0.claimant_id')->assertJsonMissingPath('data.0.evidence_text');
        $this->getJson($this->listUrl('returned', '&q=no%20claims'))->assertOk()->assertJsonPath('total', 0);
        $this->getJson($this->listUrl('stored', '&q=no%20claims'))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.item_id', $none);
        $this->postJson($this->reviewUrl('return'))->assertConflict();
    }

    public function test_history_location_filter_works_after_return_without_duplicate_items(): void
    {
        $this->setClock('12:10:00.123456');
        app(TransferFoundItem::class)->execute($this->item->item_id, $this->destination->location_id, $this->staffUser->staff_id);
        $this->setClock('12:20:00.123456');
        app(TransferFoundItem::class)->execute($this->item->item_id, $this->location->location_id, $this->staffUser->staff_id);
        $this->getJson($this->listUrl('stored', '&location_scope=history&location_id='.$this->location->location_id))->assertOk()->assertJsonPath('total', 1);
        $this->setClock('13:00:00.123456');
        $this->postJson($this->reviewUrl('return'))->assertOk();
        $this->getJson($this->listUrl('returned', '&location_id='.$this->location->location_id))->assertOk()->assertJsonPath('total', 0);
        $this->getJson($this->listUrl('returned', '&location_scope=history&location_id='.$this->location->location_id.'&received_from=2026-09-30&received_to=2026-09-30&q=wallet'))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.current_location_id', null);
        $this->getJson('/api/items/'.$this->item->item_id.'/storage-history')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson($this->listUrl('all', '&location_scope=invalid'))->assertUnprocessable();
    }

    public function test_missing_resolution_is_owner_scoped_and_does_not_return_an_item(): void
    {
        $owner = User::factory()->create(['person_id' => $this->people[0]->person_id]);
        $other = User::factory()->create(['person_id' => $this->people[1]->person_id]);
        Sanctum::actingAs($owner, ['user']);
        $ids = [];
        foreach (range(1, 5) as $n) {
            $ids[] = $this->postJson('/api/my/missing-reports', ['item_description' => 'Missing fixture '.$n, 'last_known_location' => 'Private place', 'lost_at' => '2026-09-30T08:00:00+08:00'])->assertCreated()->json('data.report_id');
        }
        $before = $this->snapshot();
        foreach (array_slice($ids, 0, 2) as $id) {
            $this->postJson('/api/my/missing-reports/'.$id.'/resolve')->assertOk();
        }
        $this->postJson('/api/my/missing-reports/'.$ids[2].'/withdraw')->assertOk();
        $this->assertSame($before, $this->snapshot());
        $this->getJson('/api/my/missing-reports?status=OPEN&per_page=1&page=2')->assertOk()->assertJsonPath('total', 2)->assertJsonCount(1, 'data');
        $this->getJson('/api/my/missing-reports?status=RESOLVED&per_page=1&page=2')->assertOk()->assertJsonPath('total', 2)->assertJsonCount(1, 'data');
        $this->getJson('/api/my/missing-reports?status=WITHDRAWN')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/my/missing-reports?status=RETURNED')->assertUnprocessable();
        $this->getJson('/api/items?status=returned')->assertForbidden();
        $this->getJson('/api/items/'.$this->item->item_id.'/claims')->assertForbidden();
        Sanctum::actingAs($other, ['user']);
        $this->getJson('/api/my/missing-reports?status=RESOLVED')->assertOk()->assertJsonPath('total', 0);
        $this->postJson('/api/my/missing-reports/'.$ids[3].'/resolve')->assertNotFound();
    }
}
