<?php

namespace Tests\Feature;

use App\Actions\Items\ReceiveFoundItem;
use App\Actions\Claims\SubmitClaim;
use App\Models\Category;
use App\Models\Person;
use App\Models\Staff;
use App\Models\StorageLocation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class UserWorkflowConcurrencyTest extends TestCase
{
    private array $workers = [];
    private int $staffId;
    private int $staffUserId;
    private int $userId;
    private int $personId;
    private int $secondPersonId;
    private int $categoryId;
    private int $locationId;
    private int $itemId;
    private int $reportId;
    private int $foundItemCountBefore;
    private string $staffToken;
    private string $userToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('reclaim_test_claims', DB::selectOne('SELECT DATABASE() AS name')->name);
        $this->foundItemCountBefore = DB::table('found_item')->count();
        $suffix = bin2hex(random_bytes(4));
        DB::transaction(function () use ($suffix): void {
            $staff = Staff::create(['full_name' => 'Concurrent user workflow '.$suffix]); $this->staffId = $staff->staff_id;
            $staffUser = User::factory()->create(); $staffUser->staff()->associate($staff)->save(); $this->staffUserId = $staffUser->id;
            $person = Person::create(['full_name' => 'Concurrent reporter '.$suffix, 'contact_details' => 'concurrent-'.$suffix.'@example.test']); $this->personId = $person->person_id;
            $user = User::factory()->create(); $user->person()->associate($person)->save(); $this->userId = $user->id;
            $otherPerson = Person::create(['full_name' => 'Concurrent other '.$suffix, 'contact_details' => 'other-'.$suffix.'@example.test']); $this->secondPersonId = $otherPerson->person_id;
            $category = Category::create(['category_name' => 'Concurrent category '.$suffix]); $this->categoryId = $category->category_id;
            $location = StorageLocation::create(['location_code' => 'CON-'.$suffix, 'location_description' => 'Concurrent test cabinet']); $this->locationId = $location->location_id;
            $now = CarbonImmutable::now(config('app.timezone'));
            $item = app(ReceiveFoundItem::class)->execute([
                'finder_id' => $this->personId, 'category_id' => $this->categoryId, 'initial_location_id' => $this->locationId,
                'item_description' => 'Concurrent found item '.$suffix, 'found_place' => 'Fictional test place', 'found_at' => $now->subHours(2)->format('Y-m-d\TH:i:sP'),
            ], $this->staffId); $this->itemId = $item->item_id;
            app(SubmitClaim::class)->execute($this->itemId, ['claimant_id' => $this->personId, 'evidence_text' => 'Evidence for competing return']);
            DB::table('public_item_listing')->insert([
                'item_id' => $this->itemId, 'public_description' => 'Safe concurrent public summary',
                'published_by' => $this->staffId, 'published_at' => $now->format('Y-m-d H:i:s.u'),
            ]);
            $this->reportId = DB::table('finder_report')->insertGetId([
                'user_id' => $this->userId, 'item_description' => 'Concurrent handover item', 'found_place' => 'Test foyer',
                'found_at' => $now->subHours(1)->format('Y-m-d H:i:s.u'), 'category_id' => $this->categoryId,
                'submitted_at' => $now->format('Y-m-d H:i:s.u'), 'status' => 'PENDING',
                'created_at' => $now->format('Y-m-d H:i:s.u'), 'updated_at' => $now->format('Y-m-d H:i:s.u'),
            ], 'report_id');
            $this->staffToken = $staffUser->createToken('concurrent-user-workflow-staff', ['staff'])->plainTextToken;
            $this->userToken = $user->createToken('concurrent-user-workflow-user', ['user'])->plainTextToken;
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) if ($worker->isRunning()) $worker->stop(0);
        DB::transaction(function (): void {
            $createdItemIds = DB::table('found_item')->where('received_by', $this->staffId)->pluck('item_id')->all();
            DB::table('claim')->whereIn('item_id', $createdItemIds)->delete();
            DB::table('storage_assignment')->whereIn('item_id', $createdItemIds)->delete();
            DB::table('public_item_listing')->whereIn('item_id', $createdItemIds)->delete();
            DB::table('finder_report')->where('report_id', $this->reportId)->delete();
            DB::table('found_item')->whereIn('item_id', $createdItemIds)->delete();
            DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', [$this->staffUserId, $this->userId])->delete();
            DB::table('users')->whereIn('id', [$this->staffUserId, $this->userId])->delete();
            DB::table('staff')->where('staff_id', $this->staffId)->delete();
            DB::table('person')->whereIn('person_id', [$this->personId, $this->secondPersonId])->delete();
            DB::table('category')->where('category_id', $this->categoryId)->delete();
            DB::table('storage_location')->where('location_id', $this->locationId)->delete();
        });
        parent::tearDown();
    }

    public function test_concurrent_finder_handover_requests_serialize_on_report_lock(): void
    {
        $url = '/api/staff/finder-reports/'.$this->reportId.'/receive';
        [$first, $stream] = $this->start($this->staffToken, $url, ['category_id' => $this->categoryId, 'initial_location_id' => $this->locationId], 'finder_report', true);
        $this->waitFor($first, 'LOCKED'); $blockingId = $this->connectionId($first);
        [$second] = $this->start($this->staffToken, $url, ['category_id' => $this->categoryId, 'initial_location_id' => $this->locationId], 'finder_report', false);
        $this->waitFor($second, 'TRY_LOCK'); $this->waitForLockWait($this->connectionId($second), $blockingId);
        $stream->write("RELEASE\n"); $stream->close(); $first->wait(); $second->wait();
        $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput()); $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
        $this->assertSame(201, $this->parseResult($first)['status']); $this->assertSame(409, $this->parseResult($second)['status']);
        $this->assertSame(1, DB::table('finder_report')->where('report_id', $this->reportId)->whereNotNull('received_item_id')->count());
        $receivedId = DB::table('finder_report')->where('report_id', $this->reportId)->value('received_item_id');
        $this->assertDatabaseHas('found_item', ['item_id' => $receivedId, 'received_by' => $this->staffId]);
        $this->assertSame(2, DB::table('found_item')->where('received_by', $this->staffId)->count());
    }

    public function test_concurrent_user_claim_waits_for_staff_return_and_cannot_claim_returned_item(): void
    {
        $returnUrl = '/api/items/'.$this->itemId.'/claims/'.$this->personId.'/return';
        [$first, $stream] = $this->start($this->staffToken, $returnUrl, [], 'found_item', true);
        $this->waitFor($first, 'LOCKED'); $blockingId = $this->connectionId($first);
        [$second] = $this->start($this->userToken, '/api/my/items/'.$this->itemId.'/claims', ['evidence_text' => 'Concurrent second claimant evidence'], 'found_item', false);
        $this->waitFor($second, 'TRY_LOCK'); $this->waitForLockWait($this->connectionId($second), $blockingId);
        $stream->write("RELEASE\n"); $stream->close(); $first->wait(); $second->wait();
        $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput()); $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
        $this->assertSame(200, $this->parseResult($first)['status']); $this->assertSame(409, $this->parseResult($second)['status']);
        $this->assertDatabaseHas('claim', ['item_id' => $this->itemId, 'claimant_id' => $this->personId, 'outcome' => 'RETURNED']);
        $this->assertSame(1, DB::table('claim')->where('item_id', $this->itemId)->count());
        $returnedAt = DB::table('claim')->where('item_id', $this->itemId)->where('outcome', 'RETURNED')->value('reviewed_at');
        $this->assertDatabaseHas('storage_assignment', ['item_id' => $this->itemId, 'removed_at' => $returnedAt]);
    }

    private function start(string $token, string $url, array $body, string $lockTable, bool $hold): array
    {
        $input = new InputStream;
        $input->write(json_encode(['database' => DB::connection()->getConfig(), 'token' => $token, 'url' => $url,
            'body' => (object) $body, 'lock_table' => $lockTable, 'hold' => $hold,
            'clock' => CarbonImmutable::now(config('app.timezone'))->addMinutes(5)->format('Y-m-d H:i:s.u')], JSON_THROW_ON_ERROR)."\n");
        $process = new Process([PHP_BINARY, base_path('tests/Support/user-workflow-concurrent-request.php')], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'reclaim_test_claims', 'DB_URL' => '',
        ], $input, 30);
        $this->workers[] = $process; $process->start(); return [$process, $input];
    }

    private function waitFor(Process $process, string $marker): void
    {
        $deadline = microtime(true) + 15;
        do { if (str_contains($process->getOutput(), $marker)) return; if (! $process->isRunning()) break; usleep(10000); } while (microtime(true) < $deadline);
        $this->fail('Worker did not reach '.$marker.'. '.$process->getErrorOutput().' '.$process->getOutput());
    }

    private function connectionId(Process $process): int
    {
        $this->assertSame(1, preg_match('/CONNECTION:(\d+)/', $process->getOutput(), $m)); return (int) $m[1];
    }

    private function waitForLockWait(int $waitingId, int $blockingId): void
    {
        $deadline = microtime(true) + 10;
        do {
            $wait = DB::selectOne('SELECT COUNT(*) AS n FROM performance_schema.data_lock_waits w JOIN performance_schema.threads r ON r.THREAD_ID=w.REQUESTING_THREAD_ID JOIN performance_schema.threads b ON b.THREAD_ID=w.BLOCKING_THREAD_ID WHERE r.PROCESSLIST_ID=? AND b.PROCESSLIST_ID=?', [$waitingId, $blockingId]);
            if ((int) $wait->n > 0) return; usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('MySQL did not observe the competing user workflow waiting on the locked row.');
    }

    private function parseResult(Process $process): array
    {
        $this->assertSame(1, preg_match('/RESULT:(.+)/', $process->getOutput(), $m)); return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
    }
}