<?php

namespace Tests\Feature;

use App\Actions\Items\ReturnFoundItem;
use App\Actions\Items\TransferFoundItem;
use App\Actions\ReferenceData\SaveReferenceData;
use App\Models\Category;
use App\Models\Person;
use App\Models\StorageLocation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ReviewFixtures;
use Tests\TestCase;

class ReferenceDataTest extends TestCase
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

    public static function entities(): array
    {
        return [
            'people' => ['people', 'person_id', ['full_name' => 'Sample Person', 'contact_details' => 'person@example.test'], Person::class],
            'categories' => ['categories', 'category_id', ['category_name' => 'Sample Category'], Category::class],
            'storage locations' => ['storage-locations', 'location_id', ['location_code' => 'SAMPLE-A', 'location_description' => 'Sample cabinet'], StorageLocation::class],
        ];
    }

    private function recordFor(string $endpoint): object
    {
        return match ($endpoint) {
            'people' => $this->people[0],
            'categories' => $this->category,
            'storage-locations' => $this->location,
        };
    }

    #[DataProvider('entities')]
    public function test_staff_can_create_view_and_update_without_changing_identifiers(string $endpoint, string $key, array $data, string $model): void
    {
        $response = $this->postJson('/api/'.$endpoint, $data)->assertCreated();
        $id = $response->json('data.'.$key);
        $this->assertIsInt($id);
        $response->assertJsonCount(count($data) + ($endpoint === 'people' ? 2 : 1), 'data');
        if ($endpoint === 'people') $response->assertJsonPath('data.is_account_holder', false);
        foreach ($data as $field => $value) {
            $response->assertJsonPath('data.'.$field, $value);
        }
        $view = $this->getJson('/api/'.$endpoint.'/'.$id)->assertOk()->assertJsonPath('data.'.$key, $id);
        foreach ($data as $field => $value) {
            $view->assertJsonPath('data.'.$field, $value);
        }
        $changed = array_map(fn ($value) => $value.' updated', $data);
        $this->putJson('/api/'.$endpoint.'/'.$id, $changed)->assertOk()->assertJsonPath('data.'.$key, $id);
        foreach ($changed as $field => $value) {
            $this->assertDatabaseHas((new $model)->getTable(), [$key => $id, $field => $value]);
        }
        $field = array_key_first($data);
        $this->patchJson('/api/'.$endpoint.'/'.$id, [$field => $data[$field]])->assertOk()->assertJsonPath('data.'.$key, $id);
        foreach ($changed as $otherField => $value) {
            if ($otherField !== $field) {
                $this->assertDatabaseHas((new $model)->getTable(), [$key => $id, $otherField => $value]);
            }
        }
    }

    #[DataProvider('entities')]
    public function test_exact_maximum_lengths_are_accepted(string $endpoint, string $key, array $data, string $model): void
    {
        $limits = ['full_name' => 120, 'contact_details' => 200, 'category_name' => 60, 'location_code' => 30, 'location_description' => 200];
        $data = array_map(fn ($field) => str_repeat('x', $limits[$field]), array_combine(array_keys($data), array_keys($data)));
        $this->postJson('/api/'.$endpoint, $data)->assertCreated();
    }

    public static function invalidFields(): array
    {
        return [
            'missing person name' => ['people', ['contact_details' => 'demo@example.test'], 'full_name'],
            'missing person contact' => ['people', ['full_name' => 'Demo'], 'contact_details'],
            'blank person name' => ['people', ['full_name' => " \t ", 'contact_details' => 'contact'], 'full_name'],
            'blank contact' => ['people', ['full_name' => 'Demo', 'contact_details' => " \n "], 'contact_details'],
            'long name' => ['people', ['full_name' => str_repeat('x', 121), 'contact_details' => 'contact'], 'full_name'],
            'long contact' => ['people', ['full_name' => 'Demo', 'contact_details' => str_repeat('x', 201)], 'contact_details'],
            'missing category name' => ['categories', [], 'category_name'],
            'blank category name' => ['categories', ['category_name' => " \t "], 'category_name'],
            'long category name' => ['categories', ['category_name' => str_repeat('x', 61)], 'category_name'],
            'category array' => ['categories', ['category_name' => ['bad']], 'category_name'],
            'missing location code' => ['storage-locations', ['location_description' => 'Cabinet'], 'location_code'],
            'missing location description' => ['storage-locations', ['location_code' => 'C-1'], 'location_description'],
            'blank location code' => ['storage-locations', ['location_code' => ' ', 'location_description' => 'Cabinet'], 'location_code'],
            'blank location description' => ['storage-locations', ['location_code' => 'C-1', 'location_description' => ' '], 'location_description'],
            'long location code' => ['storage-locations', ['location_code' => str_repeat('x', 31), 'location_description' => 'Cabinet'], 'location_code'],
            'long location description' => ['storage-locations', ['location_code' => 'C-1', 'location_description' => str_repeat('x', 201)], 'location_description'],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_create_and_update_leave_data_unchanged(string $endpoint, array $data, string $field): void
    {
        $record = $this->recordFor($endpoint);
        $before = $record->fresh()->getAttributes();
        $count = $record->newQuery()->count();
        $this->postJson('/api/'.$endpoint, $data)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->putJson('/api/'.$endpoint.'/'.$record->getKey(), $data)->assertUnprocessable()->assertJsonValidationErrors($field);
        if (array_key_exists($field, $data)) {
            $this->patchJson('/api/'.$endpoint.'/'.$record->getKey(), [$field => $data[$field]])
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame($before, $record->fresh()->getAttributes());
        $this->assertSame($count, $record->newQuery()->count());
    }

    #[DataProvider('entities')]
    public function test_lists_are_paginated_searchable_and_deterministically_ordered(string $endpoint, string $key, array $data, string $model): void
    {
        $first = $this->postJson('/api/'.$endpoint, $data)->assertCreated()->json('data.'.$key);
        $field = array_key_first($data);
        $data[$field] .= ' Z';
        $this->postJson('/api/'.$endpoint, $data)->assertCreated();
        $response = $this->getJson('/api/'.$endpoint.'?q=sample&per_page=1&page=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.'.$key, $first);
        $this->assertStringContainsString('q=sample', $response->json('links.next'));
        $this->getJson('/api/'.$endpoint.'?q=sample&per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/'.$endpoint.'?q=not-present')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/'.$endpoint.'?per_page=101&page=0&q='.str_repeat('x', 201))->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page', 'page', 'q']);
    }

    public function test_people_search_includes_contacts_and_stable_ties(): void
    {
        $first = Person::create(['full_name' => 'Same Name', 'contact_details' => 'needle@example.test']);
        $second = Person::create(['full_name' => 'Same Name', 'contact_details' => 'needle-phone']);
        $this->getJson('/api/people?q=needle')->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.person_id', $first->person_id)->assertJsonPath('data.1.person_id', $second->person_id);
    }

    public function test_location_search_includes_descriptions(): void
    {
        $record = StorageLocation::create(['location_code' => 'SEARCH-1', 'location_description' => 'Unique descriptive needle']);
        $this->getJson('/api/storage-locations?q=descriptive')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.location_id', $record->location_id);
    }

    #[DataProvider('entities')]
    public function test_search_treats_wildcards_and_quotes_as_literal_text(string $endpoint, string $key, array $data, string $model): void
    {
        $field = array_key_first($data);
        $data[$field] = "Literal!_%' marker";
        $id = $this->postJson('/api/'.$endpoint, $data)->assertCreated()->json('data.'.$key);
        foreach (['!_%', "' marker", "' OR 1=1 --"] as $search) {
            $response = $this->getJson('/api/'.$endpoint.'?q='.rawurlencode($search))->assertOk();
            $response->assertJsonPath('meta.total', $search === "' OR 1=1 --" ? 0 : 1);
            if ($search !== "' OR 1=1 --") {
                $response->assertJsonPath('data.0.'.$key, $id);
            }
        }
    }

    #[DataProvider('entities')]
    public function test_unauthorized_accounts_cannot_read_or_write_reference_data(string $endpoint, string $key, array $data, string $model): void
    {
        $id = $this->recordFor($endpoint)->getKey();
        $before = $this->recordFor($endpoint)->fresh()->getAttributes();
        $count = (new $model)->newQuery()->count();
        foreach (['anonymous', 'unlinked', 'limited-token'] as $kind) {
            auth()->forgetGuards();
            if ($kind === 'unlinked') {
                Sanctum::actingAs(User::factory()->create(), ['staff']);
            } elseif ($kind === 'limited-token') {
                Sanctum::actingAs($this->staffUser, ['read-only']);
            }
            $expected = $kind === 'anonymous' ? 401 : 403;
            $this->getJson('/api/'.$endpoint)->assertStatus($expected);
            $this->getJson('/api/'.$endpoint.'/'.$id)->assertStatus($expected);
            $this->postJson('/api/'.$endpoint, $data)->assertStatus($expected);
            $this->putJson('/api/'.$endpoint.'/'.$id, $data)->assertStatus($expected);
            $this->patchJson('/api/'.$endpoint.'/'.$id, $data)->assertStatus($expected);
        }
        $this->assertSame($before, $this->recordFor($endpoint)->fresh()->getAttributes());
        $this->assertSame($count, (new $model)->newQuery()->count());
    }

    #[DataProvider('entities')]
    public function test_identifiers_cannot_be_supplied_or_changed(string $endpoint, string $key, array $data, string $model): void
    {
        $record = $this->recordFor($endpoint);
        $before = $record->fresh()->getAttributes();
        $this->postJson('/api/'.$endpoint, array_merge($data, [$key => 999]))->assertUnprocessable()->assertJsonValidationErrors($key);
        $this->putJson('/api/'.$endpoint.'/'.$record->getKey(), array_merge($data, [$key => 999]))->assertUnprocessable()->assertJsonValidationErrors($key);
        $this->patchJson('/api/'.$endpoint.'/'.$record->getKey(), [$key => 999])->assertUnprocessable()->assertJsonValidationErrors($key);
        $this->assertSame($before, $record->fresh()->getAttributes());
    }

    #[DataProvider('entities')]
    public function test_missing_records_return_404(string $endpoint, string $key, array $data, string $model): void
    {
        $this->getJson('/api/'.$endpoint.'/999999999')->assertNotFound();
        $this->putJson('/api/'.$endpoint.'/999999999', $data)->assertNotFound();
        $this->patchJson('/api/'.$endpoint.'/999999999', $data)->assertNotFound();
    }

    public static function uniqueEntities(): array
    {
        return [
            'category' => ['categories', 'category_id', ['category_name' => 'Unique Test Name'], Category::class, 'category_name'],
            'location' => ['storage-locations', 'location_id', ['location_code' => 'UNIQUE-A', 'location_description' => 'Cabinet'], StorageLocation::class, 'location_code'],
        ];
    }

    #[DataProvider('uniqueEntities')]
    public function test_unique_fields_follow_mysql_collation_and_allow_unchanged_values(string $endpoint, string $key, array $data, string $model, string $field): void
    {
        $id = $this->postJson('/api/'.$endpoint, $data)->assertCreated()->json('data.'.$key);
        $this->putJson('/api/'.$endpoint.'/'.$id, $data)->assertOk();
        $this->patchJson('/api/'.$endpoint.'/'.$id, [$field => $data[$field]])->assertOk();
        $duplicate = $data;
        $duplicate[$field] = '  '.strtolower($data[$field]).'  ';
        $this->postJson('/api/'.$endpoint, $duplicate)->assertUnprocessable()->assertJsonValidationErrors($field);
        $record = $this->recordFor($endpoint);
        $before = $record->fresh()->getAttributes();
        $this->putJson('/api/'.$endpoint.'/'.$record->getKey(), $data)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson('/api/'.$endpoint.'/'.$record->getKey(), [$field => $data[$field]])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $record->fresh()->getAttributes());
    }

    #[DataProvider('uniqueEntities')]
    public function test_database_duplicate_safeguard_returns_validation_error_without_partial_write(string $endpoint, string $key, array $data, string $model, string $field): void
    {
        $existing = (new $model)->newQuery()->create($data);
        $other = $this->recordFor($endpoint);
        $before = $other->fresh()->getAttributes();
        foreach ([new $model, $other] as $record) {
            try {
                app(SaveReferenceData::class)->execute($record, $data);
                $this->fail('The database unique index must reject the duplicate.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }
        $this->assertSame($before, $other->fresh()->getAttributes());
        $this->assertSame(1, (new $model)->newQuery()->where($field, $existing->$field)->count());
    }

    public function test_referenced_records_can_be_updated_without_changing_claim_or_storage_history(): void
    {
        $this->setClock('11:30:00');
        app(TransferFoundItem::class)->execute($this->item->item_id, $this->destination->location_id, $this->staffUser->staff_id);
        $this->setClock('12:00:00');
        app(ReturnFoundItem::class)->execute($this->item->item_id, $this->people[0]->person_id, $this->staffUser->staff_id);
        $before = $this->snapshot();
        $itemBefore = $this->item->fresh()->getAttributes();
        $this->patchJson('/api/people/'.$this->people[0]->person_id, ['full_name' => 'Corrected Finder Name', 'contact_details' => 'updated@example.test'])->assertOk();
        $this->patchJson('/api/people/'.$this->people[1]->person_id, ['full_name' => 'Corrected Claimant Name'])->assertOk();
        $this->patchJson('/api/categories/'.$this->category->category_id, ['category_name' => 'Corrected Category'])->assertOk();
        $this->patchJson('/api/storage-locations/'.$this->location->location_id, ['location_code' => 'OLD-CABINET', 'location_description' => 'Corrected former location'])->assertOk();
        $this->patchJson('/api/storage-locations/'.$this->destination->location_id, ['location_code' => 'CURRENT-CABINET'])->assertOk();
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($itemBefore, $this->item->fresh()->getAttributes());
    }

    #[DataProvider('entities')]
    public function test_delete_is_not_exposed_and_referenced_records_remain(string $endpoint, string $key, array $data, string $model): void
    {
        $record = $this->recordFor($endpoint);
        $before = $this->snapshot();
        $this->deleteJson('/api/'.$endpoint.'/'.$record->getKey())->assertStatus(405);
        $this->assertDatabaseHas($record->getTable(), [$key => $record->getKey()]);
        $this->assertSame($before, $this->snapshot());
    }

    public static function referencedRows(): array
    {
        return [
            'finder' => ['person', 'person_id', 'finder'],
            'claimant' => ['person', 'person_id', 'claimant'],
            'category' => ['category', 'category_id', 'category'],
            'closed location' => ['storage_location', 'location_id', 'location'],
        ];
    }

    #[DataProvider('referencedRows')]
    public function test_mysql_foreign_keys_protect_referenced_records(string $table, string $key, string $kind): void
    {
        $this->setClock('11:30:00');
        app(TransferFoundItem::class)->execute($this->item->item_id, $this->destination->location_id, $this->staffUser->staff_id);
        $id = match ($kind) {
            'finder' => $this->people[0]->person_id,
            'claimant' => $this->people[1]->person_id,
            'category' => $this->category->category_id,
            'location' => $this->location->location_id,
        };
        try {
            DB::table($table)->where($key, $id)->delete();
            $this->fail('Referenced records must be protected by the database.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->errorInfo[0]);
            $this->assertSame(1451, $exception->errorInfo[1]);
        }
        $this->assertDatabaseHas($table, [$key => $id]);
    }
}
