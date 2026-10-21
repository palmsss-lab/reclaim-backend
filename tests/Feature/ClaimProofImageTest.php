<?php

namespace Tests\Feature;

use App\Actions\Items\ReceiveFoundItem;
use App\Models\Category;
use App\Models\Person;
use App\Models\Staff;
use App\Models\StorageLocation;
use App\Models\User;
use App\Services\ImageFiles;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClaimProofImageTest extends TestCase
{
    private User $owner;

    private User $other;

    private User $staff;

    private int $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('reclaim_test_claims', DB::selectOne('SELECT DATABASE() AS name')->name);
        $this->assertSame('3307', (string) config('database.connections.mysql.port'));
        Storage::fake('reclaim_images');
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function (): void {
            while (DB::transactionLevel()) {
                DB::rollBack();
            }
        });
        $this->owner = $this->account();
        $this->other = $this->account();
        $staff = Staff::create(['full_name' => 'Proof review staff']);
        $this->staff = User::factory()->create(['staff_id' => $staff->staff_id]);
        $category = Category::create(['category_name' => 'Claim proof '.bin2hex(random_bytes(5))]);
        $location = StorageLocation::create(['location_code' => 'PROOF-'.bin2hex(random_bytes(4)), 'location_description' => 'Private cabinet']);
        $this->item = app(ReceiveFoundItem::class)->execute([
            'finder_id' => $this->owner->person_id, 'category_id' => $category->category_id,
            'initial_location_id' => $location->location_id, 'item_description' => 'Private item',
            'found_place' => 'Private place', 'found_at' => now()->subDay()->toIso8601String(),
        ], $staff->staff_id)->item_id;
        Sanctum::actingAs($this->staff, ['staff']);
        $this->putJson('/api/staff/items/'.$this->item.'/public-listing', ['public_description' => 'Safe test item'])->assertCreated();
        Sanctum::actingAs($this->owner, ['user']);
    }

    private function account(): User
    {
        $person = Person::create(['full_name' => 'Proof owner', 'contact_details' => 'fictional@example.test']);

        return User::factory()->create(['person_id' => $person->person_id]);
    }

    private function submit(?UploadedFile $image = null, array $extra = [])
    {
        return $this->postJson('/api/my/items/'.$this->item.'/claims', $extra + [
            'evidence_text' => 'Private written evidence',
        ] + ($image ? ['image' => $image] : []));
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->image('proof.png', 64, 48);
    }

    private function ownerPath(): string
    {
        return '/api/my/claims/'.$this->item.'/proof-image';
    }

    private function staffPath(?int $claimant = null): string
    {
        return '/api/items/'.$this->item.'/claims/'.($claimant ?? $this->owner->person_id).'/proof-image';
    }

    public function test_claim_without_photo_still_succeeds_and_keeps_written_evidence_required(): void
    {
        $this->submit(null, ['evidence_text' => ' '])->assertUnprocessable()->assertJsonValidationErrors('evidence_text');
        $this->submit()->assertCreated()->assertJsonPath('data.has_proof_image', 0);
        $this->assertDatabaseHas('claim', ['item_id' => $this->item, 'claimant_id' => $this->owner->person_id, 'evidence_text' => 'Private written evidence', 'outcome' => 'PENDING']);
        $this->assertDatabaseCount('claim_proof_image', 0);
        $this->assertSame([], Storage::disk('reclaim_images')->allFiles());
        $this->getJson($this->ownerPath())->assertNotFound();
    }

    public function test_photo_is_normalized_and_bound_to_both_claim_keys_without_publication(): void
    {
        $this->submit($this->photo())->assertCreated()->assertJsonPath('data.has_proof_image', 1)->assertJsonMissingPath('data.path');
        $row = DB::table('claim_proof_image')->where('item_id', $this->item)->where('claimant_id', $this->owner->person_id)->first();
        $this->assertNotNull($row);
        Storage::disk('reclaim_images')->assertExists($row->path);
        $this->assertSame('private', config('filesystems.disks.reclaim_images.visibility'));
        $this->assertFalse(config('filesystems.disks.reclaim_images.serve'));
        $response = $this->get($this->ownerPath())->assertOk()->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($response->getContent())[2]);
        $this->getJson('/api/my/claims')->assertOk()->assertJsonPath('data.0.has_proof_image', 1)->assertDontSee($row->path);
        $this->getJson('/api/public/items')->assertOk()->assertJsonCount(0, 'data.0.images')->assertDontSee($row->path)->assertDontSee('has_proof_image')->assertDontSee('Private written evidence');
        $this->getJson('/api/public/items/'.$this->item)->assertOk()->assertJsonCount(0, 'data.images')->assertDontSee($row->path)->assertDontSee('has_proof_image');
        $this->assertDatabaseCount('image_attachment', 0);

        $this->submit($this->photo())->assertConflict();
        $this->assertCount(1, Storage::disk('reclaim_images')->allFiles());
        $this->assertDatabaseCount('claim_proof_image', 1);
    }

    public function test_only_owner_and_authorized_staff_can_read_the_exact_claim_photo(): void
    {
        $this->submit($this->photo())->assertCreated();
        Sanctum::actingAs($this->other, ['user']);
        $this->getJson($this->ownerPath())->assertNotFound();
        $this->getJson($this->staffPath())->assertForbidden();
        $this->getJson('/api/my/claims')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/my/claims/'.$this->item)->assertNotFound();
        $this->submit(UploadedFile::fake()->image('other.png', 32, 24))->assertCreated();
        $this->assertSame(32, getimagesizefromstring($this->get($this->ownerPath())->assertOk()->getContent())[0]);
        $this->assertDatabaseCount('claim_proof_image', 2);
        $this->assertCount(2, Storage::disk('reclaim_images')->allFiles());

        Sanctum::actingAs($this->staff, ['staff']);
        $this->assertSame(64, getimagesizefromstring($this->get($this->staffPath())->assertOk()->getContent())[0]);
        $this->assertSame(32, getimagesizefromstring($this->get($this->staffPath($this->other->person_id))->assertOk()->getContent())[0]);
        $this->getJson($this->staffPath(999999))->assertNotFound();
        $this->getJson('/api/items/'.$this->item.'/claims')->assertOk()->assertJsonPath('data.0.has_proof_image', true);
        $this->getJson('/api/reports/pending-claims')->assertOk()->assertJsonPath('data.0.has_proof_image', true);
        $this->getJson($this->ownerPath())->assertForbidden();
        Sanctum::actingAs($this->staff, ['user']);
        $this->getJson($this->staffPath())->assertForbidden();
        Sanctum::actingAs($this->owner, ['staff']);
        $this->getJson($this->ownerPath())->assertForbidden();
        $this->getJson($this->staffPath())->assertForbidden();
    }

    public function test_schema_enforces_one_image_per_exact_claim_and_authentication_is_required(): void
    {
        $this->submit($this->photo())->assertCreated();
        $row = (array) DB::table('claim_proof_image')->where('item_id', $this->item)->where('claimant_id', $this->owner->person_id)->first();
        foreach ([
            array_replace($row, ['claimant_id' => $this->other->person_id, 'path' => 'nonexistent.jpg']),
            array_replace($row, ['path' => 'duplicate.jpg']),
        ] as $invalid) {
            try {
                DB::table('claim_proof_image')->insert($invalid);
                $this->fail('Invalid proof metadata must be rejected.');
            } catch (QueryException $error) {
                $this->assertSame('23000', $error->getCode());
            }
        }
        $this->app['auth']->forgetGuards();
        $this->getJson($this->ownerPath())->assertUnauthorized();
        $this->getJson($this->staffPath())->assertUnauthorized();
    }

    public function test_invalid_raster_size_dimensions_and_multiple_files_leave_no_claim_or_file(): void
    {
        $valid = $this->photo();
        $invalid = [
            UploadedFile::fake()->createWithContent('bad.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
            UploadedFile::fake()->createWithContent('bad.png', 'not an image'),
            UploadedFile::fake()->createWithContent('truncated.png', substr(file_get_contents($valid->getRealPath()), 0, 40)),
            UploadedFile::fake()->image('bad.gif', 20, 20),
            UploadedFile::fake()->create('large.png', 5121, 'image/png'),
            UploadedFile::fake()->image('wide.png', 4097, 1),
            UploadedFile::fake()->image('pixels.png', 3000, 3000),
        ];
        foreach ($invalid as $image) {
            $this->submit($image)->assertUnprocessable()->assertJsonValidationErrors('image');
            $this->assertDatabaseMissing('claim', ['item_id' => $this->item, 'claimant_id' => $this->owner->person_id]);
            $this->assertSame([], Storage::disk('reclaim_images')->allFiles());
        }
        $this->postJson('/api/my/items/'.$this->item.'/claims', ['evidence_text' => 'Text', 'image' => [$this->photo(), $this->photo()]])
            ->assertUnprocessable()->assertJsonValidationErrors('image');
    }

    public function test_valid_formats_and_size_boundary_use_the_existing_normalizer(): void
    {
        foreach (['jpg', 'png', 'webp'] as $extension) {
            $file = UploadedFile::fake()->image('proof.'.$extension, 4096, 64)->size(5120);
            $this->submit($file)->assertCreated();
            $row = DB::table('claim_proof_image')->where('item_id', $this->item)->where('claimant_id', $this->owner->person_id)->first();
            $this->assertSame(2048, $row->width);
            $this->assertSame(32, $row->height);
            DB::table('claim_proof_image')->where('item_id', $this->item)->where('claimant_id', $this->owner->person_id)->delete();
            DB::table('claim')->where('item_id', $this->item)->where('claimant_id', $this->owner->person_id)->delete();
            Storage::disk('reclaim_images')->delete($row->path);
        }
    }

    public function test_failed_metadata_insert_rolls_back_claim_and_removes_written_file(): void
    {
        $this->withoutExceptionHandling();
        DB::listen(function (QueryExecuted $event): void {
            if (str_starts_with($event->sql, 'insert into '.chr(96).'claim_proof_image')) {
                throw new \RuntimeException('Synthetic metadata failure');
            }
        });
        try {
            $this->submit($this->photo());
            $this->fail('Metadata failure must propagate.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic metadata failure', $error->getMessage());
        }
        $this->assertDatabaseMissing('claim', ['item_id' => $this->item, 'claimant_id' => $this->owner->person_id]);
        $this->assertDatabaseCount('claim_proof_image', 0);
        $this->assertSame([], Storage::disk('reclaim_images')->allFiles());
    }

    public function test_partial_file_write_failure_rolls_back_claim_and_cleans_the_file(): void
    {
        $this->withoutExceptionHandling();
        $this->mock(ImageFiles::class, function ($mock): void {
            $mock->makePartial()->shouldReceive('put')->once()->andReturnUsing(function ($path, $content): void {
                Storage::disk('reclaim_images')->put($path, $content);
                throw new \RuntimeException('Synthetic storage failure');
            });
        });
        try {
            $this->submit($this->photo());
            $this->fail('Storage failure must propagate.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic storage failure', $error->getMessage());
        }
        $this->assertDatabaseMissing('claim', ['item_id' => $this->item, 'claimant_id' => $this->owner->person_id]);
        $this->assertDatabaseCount('claim_proof_image', 0);
        $this->assertSame([], Storage::disk('reclaim_images')->allFiles());
    }

    public function test_identity_unverified_deactivated_and_unpublished_guards_remain_enforced(): void
    {
        $this->submit($this->photo(), ['claimant_id' => $this->other->person_id])->assertUnprocessable()->assertJsonValidationErrors('claimant_id');
        $this->owner->forceFill(['email_verified_at' => null])->save();
        $this->submit($this->photo())->assertForbidden();
        $this->getJson($this->ownerPath())->assertForbidden();
        $this->owner->forceFill(['email_verified_at' => now(), 'deactivated_at' => now()])->save();
        $this->submit($this->photo())->assertForbidden();
        $this->getJson($this->ownerPath())->assertForbidden();
        $this->owner->forceFill(['deactivated_at' => null])->save();
        DB::table('public_item_listing')->where('item_id', $this->item)->update(['unpublished_at' => now()->addSecond()]);
        $this->submit($this->photo())->assertConflict();
        $this->assertDatabaseMissing('claim', ['item_id' => $this->item, 'claimant_id' => $this->owner->person_id]);
        $this->assertSame([], Storage::disk('reclaim_images')->allFiles());
    }

    public function test_proof_remains_private_after_return_and_existing_workflow_is_unchanged(): void
    {
        $this->submit($this->photo())->assertCreated();
        Sanctum::actingAs($this->other, ['user']);
        $this->submit()->assertCreated();
        Sanctum::actingAs($this->staff, ['staff']);
        $this->postJson('/api/items/'.$this->item.'/claims/'.$this->owner->person_id.'/return', [])->assertOk();
        $this->assertDatabaseHas('claim', ['item_id' => $this->item, 'claimant_id' => $this->other->person_id, 'outcome' => 'REJECTED']);
        $this->assertFalse(DB::table('storage_assignment')->where('item_id', $this->item)->whereNull('removed_at')->exists());
        $this->get($this->staffPath())->assertOk();
        $this->getJson('/api/public/items/'.$this->item)->assertNotFound();
        Sanctum::actingAs($this->owner, ['user']);
        $this->get($this->ownerPath())->assertOk();
    }
}
