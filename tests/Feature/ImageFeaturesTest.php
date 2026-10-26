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
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ImageFeaturesTest extends TestCase
{
    private User $owner;

    private User $other;

    private User $staff;

    private int $report;

    private int $missing;

    private int $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('reclaim_test_claims', DB::selectOne('SELECT DATABASE() AS name')->name);
        // Dedicated directory supplied by the isolated verification runner.
        $root = config('filesystems.disks.reclaim_images.root');
        $this->assertStringContainsString('.verification', $root);
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function (): void {
            while (DB::transactionLevel()) {
                DB::rollBack();
            }
        });
        $this->owner = $this->account();
        $this->other = $this->account();
        $staff = Staff::create(['full_name' => 'Image test staff']);
        $this->staff = User::factory()->create(['staff_id' => $staff->staff_id]);
        $category = Category::create(['category_name' => 'Images '.bin2hex(random_bytes(5))]);
        $location = StorageLocation::create(['location_code' => 'IMG-'.bin2hex(random_bytes(4)), 'location_description' => 'Private cabinet']);
        $this->item = app(ReceiveFoundItem::class)->execute([
            'finder_id' => $this->owner->person_id, 'category_id' => $category->category_id, 'initial_location_id' => $location->location_id,
            'item_description' => 'Private item', 'found_place' => 'Private place', 'found_at' => now()->subDay()->toIso8601String(),
        ], $staff->staff_id)->item_id;
        Sanctum::actingAs($this->owner, ['user']);
        $this->report = $this->postJson('/api/my/finder-reports', ['item_description' => 'Private report', 'found_place' => 'Private room', 'found_at' => now()->subDay()->toIso8601String()])->assertCreated()->json('data.report_id');
        $this->missing = $this->postJson('/api/my/missing-reports', ['item_description' => 'Missing report', 'last_known_location' => 'Private room', 'lost_at' => now()->subDay()->toIso8601String()])->assertCreated()->json('data.report_id');
        $this->beforeApplicationDestroyed(function () use ($category, $location): void {
            // Two tests commit to exercise afterCommit file cleanup. Remove only
            // their own fixtures after the rollback callback, so later suites
            // do not count these committed items/categories/storage locations.
            if (! DB::table('found_item')->where('item_id', $this->item)->exists()) {
                return;
            }
            DB::transaction(function () use ($category, $location): void {
                DB::table('image_attachment')->where('item_id', $this->item)
                    ->orWhere('finder_report_id', $this->report)->orWhere('missing_report_id', $this->missing)->delete();
                DB::table('public_item_listing')->where('item_id', $this->item)->delete();
                DB::table('storage_assignment')->where('item_id', $this->item)->delete();
                DB::table('found_item')->where('item_id', $this->item)->delete();
                DB::table('finder_report')->where('report_id', $this->report)->delete();
                DB::table('missing_item_report')->where('report_id', $this->missing)->delete();
                DB::table('users')->whereIn('id', [$this->owner->id, $this->other->id, $this->staff->id])->delete();
                DB::table('person')->whereIn('person_id', [$this->owner->person_id, $this->other->person_id])->delete();
                DB::table('staff')->where('staff_id', $this->staff->staff_id)->delete();
                DB::table('category')->where('category_id', $category->category_id)->delete();
                DB::table('storage_location')->where('location_id', $location->location_id)->delete();
            });
        });
    }

    private function account(): User
    {
        $person = Person::create(['full_name' => 'Image owner', 'contact_details' => 'private@example.test']);

        return User::factory()->create(['person_id' => $person->person_id]);
    }

    private function path(): string
    {
        return '/api/my/finder-reports/'.$this->report.'/images';
    }

    private function staffPath(): string
    {
        return '/api/items/'.$this->item.'/images';
    }

    private function upload(string $path, ?UploadedFile $file = null)
    {
        return $this->postJson($path, ['image' => $file ?? UploadedFile::fake()->image('photo.png', 64, 48)]);
    }

    public function test_image_disk_defaults_local_and_can_build_private_r2_adapter_without_public_url(): void
    {
        $original = config('filesystems.disks.reclaim_images');
        $this->assertSame('local', $original['driver']);
        $this->assertSame('private', $original['visibility']);
        $this->assertFalse($original['serve']);
        $this->assertArrayNotHasKey('url', $original);

        config(['filesystems.disks.reclaim_images' => array_replace($original, [
            'driver' => 's3',
            'root' => '',
            'key' => 'test-access-key',
            'secret' => 'test-secret-key',
            'region' => 'auto',
            'bucket' => 'private-test-bucket',
            'endpoint' => 'https://test-account.r2.cloudflarestorage.com',
        ])]);
        Storage::forgetDisk('reclaim_images');

        try {
            $disk = Storage::disk('reclaim_images');
            $this->assertInstanceOf(AwsS3V3Adapter::class, $disk);
            $this->assertSame('private', $disk->getConfig()['visibility']);
            $this->assertSame('', $disk->getConfig()['root']);
            $this->assertArrayNotHasKey('url', $disk->getConfig());
        } finally {
            Storage::forgetDisk('reclaim_images');
            config(['filesystems.disks.reclaim_images' => $original]);
        }
    }

    public function test_categories_expose_only_identifiers_and_names_without_authorizing_management(): void
    {
        $response = $this->getJson('/api/public/categories?per_page=1')->assertOk();
        $this->assertEqualsCanonicalizing(['category_id', 'category_name'], array_keys($response->json('data.0')));
        $this->getJson('/api/categories')->assertForbidden();
        $this->postJson('/api/categories', ['category_name' => 'Forbidden'])->assertForbidden();
        $this->getJson('/api/public/categories?per_page=101')->assertUnprocessable();
    }

    public function test_actual_raster_validation_limits_and_metadata_removal(): void
    {
        foreach (['png', 'jpg', 'webp'] as $format) {
            $image = imagecreatetruecolor(16, 12);
            ob_start();
            match ($format) {
                'png' => imagepng($image), 'jpg' => imagejpeg($image), 'webp' => imagewebp($image)
            };
            $bytes = ob_get_clean();
            imagedestroy($image);
            // JPEG comment simulates identifying metadata; output must not retain it.
            if ($format === 'jpg') {
                $bytes = substr($bytes, 0, 2)."\xff\xfe".pack('n', 23).'GPS-PRIVATE-LOCATION!'.substr($bytes, 2);
            }
            $file = UploadedFile::fake()->createWithContent('camera.'.$format, $bytes);
            $id = $this->upload($this->path(), $file)->assertCreated()->json('data.image_id');
            $response = $this->get($this->path().'/'.$id.'/content')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
            $this->assertStringNotContainsString('GPS-PRIVATE', $response->getContent());
            $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($response->getContent())[2]);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        foreach ([
            UploadedFile::fake()->createWithContent('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
            UploadedFile::fake()->createWithContent('malformed.png', "\x89PNG\r\n"),
            UploadedFile::fake()->createWithContent('program.jpg', '<?php echo "bad";'),
            UploadedFile::fake()->create('large.png', 5121, 'image/png'),
            UploadedFile::fake()->image('wide.png', 4097, 1),
            UploadedFile::fake()->image('pixels.png', 3000, 3000),
            UploadedFile::fake()->image('disguised.php', 10, 10),
            UploadedFile::fake()->image('animation.gif', 10, 10),
        ] as $invalid) {
            $this->upload($this->path(), $invalid)->assertUnprocessable()->assertJsonValidationErrors('image');
        }
        $this->assertSame(3, DB::table('image_attachment')->where('finder_report_id', $this->report)->count());
    }

    public function test_ownership_nested_ids_and_staff_permissions_cover_all_operations(): void
    {
        $id = $this->upload($this->path())->assertCreated()->json('data.image_id');
        $missingPath = '/api/my/missing-reports/'.$this->missing.'/images';
        $missingId = $this->upload($missingPath)->assertCreated()->json('data.image_id');
        $this->get($this->path().'/'.$missingId.'/content')->assertNotFound();
        Sanctum::actingAs($this->other, ['user']);
        foreach ([$this->path(), $missingPath] as $path) {
            $this->getJson($path)->assertNotFound();
            $this->getJson($path.'/'.$id.'/content')->assertNotFound();
            $this->upload($path)->assertNotFound();
            $this->upload($path.'/'.$id)->assertNotFound();
            $this->deleteJson($path.'/'.$id)->assertNotFound();
        }
        $this->upload($this->staffPath())->assertForbidden();
        $this->putJson($this->staffPath().'/'.$id.'/publication', ['approved' => true])->assertForbidden();
        $this->getJson('/api/staff/finder-reports/'.$this->report.'/images')->assertForbidden();
        Sanctum::actingAs($this->staff, ['staff']);
        $this->get('/api/staff/finder-reports/'.$this->report.'/images/'.$id.'/content')->assertOk();
        $this->get('/api/staff/missing-reports/'.$this->missing.'/images/'.$missingId.'/content')->assertOk();
        $this->getJson($this->path())->assertForbidden();
        Sanctum::actingAs($this->staff, ['user']);
        $this->getJson($this->staffPath())->assertForbidden();
        Sanctum::actingAs($this->owner, ['staff']);
        $this->getJson($this->path())->assertForbidden();
    }

    public function test_public_approval_replacement_revocation_and_deletion(): void
    {
        Sanctum::actingAs($this->staff, ['staff']);
        $id = $this->upload($this->staffPath())->assertCreated()->json('data.image_id');
        $public = '/api/public/items/'.$this->item.'/images/'.$id;
        $this->getJson($public)->assertNotFound();
        $this->putJson('/api/staff/items/'.$this->item.'/public-listing', ['public_description' => 'Safe bag'])->assertCreated();
        $this->getJson($public)->assertNotFound();
        $this->putJson($this->staffPath().'/'.$id.'/publication', ['approved' => true])->assertOk();
        $this->get($public)->assertOk();
        $this->getJson('/api/public/items/'.$this->item)->assertOk()->assertJsonCount(1, 'data.images')->assertJsonMissingPath('data.images.0.path')->assertJsonMissingPath('data.images.0.approved_by');
        $old = DB::table('image_attachment')->where('image_id', $id)->value('path');
        $this->upload($this->staffPath().'/'.$id)->assertOk()->assertJsonPath('data.approved', false);
        DB::commit(); // Exercise real after-commit file cleanup, on the isolated database only.
        $this->assertFalse(Storage::disk('reclaim_images')->exists($old));
        $this->getJson($public)->assertNotFound();
        $this->putJson($this->staffPath().'/'.$id.'/publication', ['approved' => true])->assertOk();
        $this->deleteJson('/api/staff/items/'.$this->item.'/public-listing')->assertOk();
        $this->getJson($public)->assertNotFound();
        $new = DB::table('image_attachment')->where('image_id', $id)->value('path');
        $this->deleteJson($this->staffPath().'/'.$id)->assertNoContent();
        $this->assertFalse(Storage::disk('reclaim_images')->exists($new));
        $this->assertDatabaseMissing('image_attachment', ['image_id' => $id]);
        $this->deleteJson($this->staffPath().'/'.$id)->assertNotFound();
    }

    public function test_parent_limit_and_closed_reports_reject_changes(): void
    {
        for ($n = 0; $n < 5; $n++) {
            $id = $this->upload($this->path())->assertCreated()->json('data.image_id');
        }
        $before = Storage::disk('reclaim_images')->allFiles();
        $this->upload($this->path())->assertConflict();
        $this->assertSame($before, Storage::disk('reclaim_images')->allFiles());
        $this->postJson('/api/my/finder-reports/'.$this->report.'/withdraw')->assertOk();
        $this->upload($this->path())->assertConflict();
        $this->upload($this->path().'/'.$id)->assertConflict();
        $this->deleteJson($this->path().'/'.$id)->assertConflict();
        $this->get($this->path().'/'.$id.'/content')->assertOk();
    }

    public function test_database_failures_preserve_old_files_and_cleanup_new_uploads(): void
    {
        $id = $this->upload($this->path())->assertCreated()->json('data.image_id');
        $old = DB::table('image_attachment')->where('image_id', $id)->value('path');
        $before = Storage::disk('reclaim_images')->allFiles();
        foreach (['insert', 'update', 'delete'] as $verb) {
            $fail = true;
            DB::listen(function (QueryExecuted $query) use (&$fail, $verb): void {
                if ($fail && str_starts_with(strtolower($query->sql), $verb) && str_contains($query->sql, 'image_attachment')) {
                    $fail = false;
                    throw new \RuntimeException('Simulated DB failure');
                }
            });
            if ($verb === 'delete') {
                $this->deleteJson($this->path().'/'.$id)->assertServerError();
            } else {
                $this->upload($this->path().($verb === 'update' ? '/'.$id : ''))->assertServerError();
            }
            $this->assertDatabaseHas('image_attachment', ['image_id' => $id, 'path' => $old]);
            $this->assertSame($before, Storage::disk('reclaim_images')->allFiles());
        }
    }

    public function test_cleanup_failure_leaves_only_an_inaccessible_orphan(): void
    {
        $id = $this->upload($this->path())->assertCreated()->json('data.image_id');
        $old = DB::table('image_attachment')->where('image_id', $id)->value('path');
        $disk = Storage::disk('reclaim_images');
        $proxy = \Mockery::mock($disk);
        $proxy->shouldReceive('delete')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('reclaim_images')->andReturn($proxy);
        DB::commit();
        $this->deleteJson($this->path().'/'.$id)->assertNoContent();
        $this->assertTrue($disk->exists($old));
        $this->assertDatabaseMissing('image_attachment', ['image_id' => $id]);
        $this->getJson($this->path().'/'.$id.'/content')->assertNotFound();
    }

    public function test_valid_size_boundary_and_resizing(): void
    {
        $file = UploadedFile::fake()->image('large-valid.png', 4096, 64)->size(5120);
        $result = $this->upload($this->path(), $file)->assertCreated();
        $result->assertJsonPath('data.width', 2048)->assertJsonPath('data.height', 32);
    }

    public function test_all_three_upload_targets_enforce_validation_and_private_storage(): void
    {
        foreach ([[$this->owner, $this->path()], [$this->owner, '/api/my/missing-reports/'.$this->missing.'/images'], [$this->staff, $this->staffPath()]] as [$account, $path]) {
            Sanctum::actingAs($account, [$account->staff_id ? 'staff' : 'user']);
            $this->upload($path, UploadedFile::fake()->createWithContent('bad.png', 'not an image'))->assertUnprocessable()->assertJsonValidationErrors('image');
            $this->upload($path, UploadedFile::fake()->create('large.png', 5121, 'image/png'))->assertUnprocessable()->assertJsonValidationErrors('image');
            $result = $this->upload($path)->assertCreated()->assertJsonPath('data.approved', false)->assertJsonMissingPath('data.path');
            $id = $result->json('data.image_id');
            $this->get($path.'/'.$id.'/content')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
            $this->getJson('/api/public/items/'.$this->item.'/images/'.$id)->assertNotFound();
        }
        Sanctum::actingAs($this->staff, ['staff']);
        $this->putJson('/api/staff/items/'.$this->item.'/public-listing', ['public_description' => 'Safe item'])->assertCreated();
        $this->getJson('/api/public/items/'.$this->item)->assertOk()->assertJsonCount(0, 'data.images');
    }

    public function test_partial_file_write_failure_cleans_up_without_a_reference(): void
    {
        $before = Storage::disk('reclaim_images')->allFiles();
        $mock = \Mockery::mock(ImageFiles::class)->makePartial();
        $mock->shouldReceive('put')->once()->andReturnUsing(function ($path, $content): void {
            Storage::disk('reclaim_images')->put($path, $content);
            throw new \RuntimeException('Simulated disk failure');
        });
        $this->app->instance(ImageFiles::class, $mock);
        $this->upload($this->path())->assertServerError();
        $this->assertSame($before, Storage::disk('reclaim_images')->allFiles());
        $this->assertSame(0, DB::table('image_attachment')->where('finder_report_id', $this->report)->count());
    }
}
