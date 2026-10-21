<?php

namespace Tests\Support;

use App\Actions\Claims\SubmitClaim;
use App\Actions\Items\ReceiveFoundItem;
use App\Models\Category;
use App\Models\FoundItem;
use App\Models\Person;
use App\Models\Staff;
use App\Models\StorageLocation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait ReviewFixtures
{
    protected User $staffUser;

    protected FoundItem $item;

    protected array $people;

    protected StorageLocation $location;

    protected StorageLocation $destination;

    protected Category $category;

    protected function assertTestDatabase(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('reclaim_test_claims', DB::selectOne('SELECT DATABASE() AS name')->name);
    }

    protected function createReviewFixtures(): void
    {
        $suffix = Str::random(10);
        $staff = Staff::create(['full_name' => 'Fictional Review Staff']);
        $this->staffUser = User::factory()->create();
        $this->staffUser->staff()->associate($staff)->save();
        $this->people = [];
        foreach (range(1, 4) as $number) {
            $this->people[] = Person::create(['full_name' => "Fictional Claimant $number", 'contact_details' => "claimant$number@example.test"]);
        }
        $this->category = Category::create(['category_name' => 'Review test '.$suffix]);
        $this->location = StorageLocation::create(['location_code' => 'REV-A-'.$suffix, 'location_description' => 'Fictional review cabinet']);
        $this->destination = StorageLocation::create(['location_code' => 'REV-B-'.$suffix, 'location_description' => 'Other fictional cabinet']);
        $this->setClock('10:00:00.123456');
        $this->item = app(ReceiveFoundItem::class)->execute([
            'finder_id' => $this->people[0]->person_id,
            'category_id' => $this->category->category_id,
            'initial_location_id' => $this->location->location_id,
            'item_description' => 'Fictional review wallet',
            'found_place' => 'Test library',
            'found_at' => '2026-09-30T08:00:00+08:00',
        ], $staff->staff_id);
        foreach ([0 => '11:00:00.100000', 1 => '11:10:00.200000', 2 => '11:15:00.300000'] as $index => $time) {
            $this->setClock($time);
            app(SubmitClaim::class)->execute($this->item->item_id, [
                'claimant_id' => $this->people[$index]->person_id,
                'evidence_text' => 'Original evidence '.$index,
            ]);
        }
        DB::table('claim')->where('item_id', $this->item->item_id)->where('claimant_id', $this->people[2]->person_id)
            ->update(['outcome' => 'REJECTED', 'reviewed_by' => $staff->staff_id, 'reviewed_at' => '2026-09-30 11:20:00.400000']);
        $this->setClock('12:00:00.654321');
    }

    protected function setClock(string $time): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-30 '.$time, 'Asia/Manila'));
    }

    protected function reviewUrl(string $operation, int $personIndex = 0, ?int $itemId = null): string
    {
        return '/api/items/'.($itemId ?? $this->item->item_id).'/claims/'.$this->people[$personIndex]->person_id.'/'.$operation;
    }

    protected function snapshot(): array
    {
        return [
            'claims' => DB::table('claim')->where('item_id', $this->item->item_id)->orderBy('claimant_id')->get()->map(fn ($row) => (array) $row)->all(),
            'storage' => DB::table('storage_assignment')->where('item_id', $this->item->item_id)->orderBy('assignment_no')->get()->map(fn ($row) => (array) $row)->all(),
        ];
    }
}
