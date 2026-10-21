<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\ReviewFixtures;
use Tests\TestCase;

class ReviewConcurrencyTest extends TestCase
{
    use ReviewFixtures;

    private array $workers = [];

    private bool $fixturesCommitted = false;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTestDatabase();
        // Competing connections must see committed fixtures, not a PHPUnit outer transaction.
        DB::transaction(function (): void {
            $this->createReviewFixtures();
            $this->token = $this->staffUser->createToken('concurrency-test', ['staff'])->plainTextToken;
        });
        $this->fixturesCommitted = true;
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(0);
                }
            }
            if ($this->fixturesCommitted) {
                $this->assertTestDatabase();
                // Remove only this test's fictional records, in foreign-key order.
                DB::transaction(function (): void {
                    DB::table('claim')->where('item_id', $this->item->item_id)->delete();
                    DB::table('storage_assignment')->where('item_id', $this->item->item_id)->delete();
                    DB::table('found_item')->where('item_id', $this->item->item_id)->delete();
                    $this->staffUser->tokens()->delete();
                    $this->staffUser->delete();
                    DB::table('staff')->where('staff_id', $this->staffUser->staff_id)->delete();
                    DB::table('person')->whereIn('person_id', array_map(fn ($person) => $person->person_id, $this->people))->delete();
                    $this->category->delete();
                    $this->location->delete();
                    $this->destination->delete();
                });
            }
        } finally {
            CarbonImmutable::setTestNow();
            parent::tearDown();
        }
    }

    public static function competingRequests(): array
    {
        return [
            'return vs different claimant return' => ['return', 'return-other', 200, 409, true],
            'return vs rejection' => ['return', 'reject', 200, 409, true],
            'return vs submission' => ['return', 'submit', 200, 409, true],
            'return vs transfer' => ['return', 'transfer', 200, 409, true],
            'transfer vs return' => ['transfer', 'return', 200, 200, true],
            'transfer vs same destination transfer' => ['transfer', 'transfer', 200, 422, false],
            'submission vs return' => ['submit', 'return', 201, 200, true],
            'rejection vs selected claimant return' => ['reject', 'return', 200, 409, false],
            'rejection vs repeated rejection' => ['reject', 'reject', 200, 409, false],
            'submission vs duplicate submission' => ['submit', 'submit', 201, 409, false],
        ];
    }

    #[DataProvider('competingRequests')]
    public function test_competing_endpoint_requests_serialize_on_parent_lock(
        string $firstOperation,
        string $secondOperation,
        int $firstStatus,
        int $secondStatus,
        bool $returned
    ): void {
        [$first, $release] = $this->startWorker($firstOperation, true, '2026-09-30 12:00:00.654321');
        $this->waitForOutput($first, 'LOCKED');
        $blockingId = $this->connectionId($first);

        [$second] = $this->startWorker($secondOperation, false, '2026-09-30 12:01:00.654321');
        $this->waitForOutput($second, 'TRY_LOCK');
        $waitingId = $this->connectionId($second);
        $this->assertNotSame($blockingId, $waitingId);
        $this->waitForDatabaseLockWait($waitingId, $blockingId);
        $this->assertStringNotContainsString('RESULT:', $second->getOutput());

        $release->write("RELEASE\n");
        $release->close();
        $first->wait();
        $second->wait();
        $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
        $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
        $this->assertSame($firstStatus, $this->workerResult($first)['status']);
        $this->assertSame($secondStatus, $this->workerResult($second)['status']);

        $claims = DB::table('claim')->where('item_id', $this->item->item_id);
        $assignments = DB::table('storage_assignment')->where('item_id', $this->item->item_id);
        $this->assertSame($returned ? 1 : 0, (clone $claims)->where('outcome', 'RETURNED')->count());
        $this->assertSame($returned ? 0 : 1, (clone $assignments)->whereNull('removed_at')->count());
        $this->assertSame($firstOperation === 'transfer' ? 2 : 1, (clone $assignments)->count());
        foreach (range(0, 2) as $index) {
            $this->assertDatabaseHas('claim', [
                'item_id' => $this->item->item_id, 'claimant_id' => $this->people[$index]->person_id,
                'evidence_text' => 'Original evidence '.$index,
            ]);
        }
        // Already-final claims are immutable even when another claim is returned.
        $this->assertDatabaseHas('claim', [
            'item_id' => $this->item->item_id, 'claimant_id' => $this->people[2]->person_id,
            'outcome' => 'REJECTED', 'reviewed_at' => '2026-09-30 11:20:00.400000',
        ]);
        if ($returned) {
            $this->assertSame(0, (clone $claims)->where('outcome', 'PENDING')->count());
            $returnTime = $firstOperation === 'return' ? '2026-09-30 12:00:00.654321' : '2026-09-30 12:01:00.654321';
            $this->assertDatabaseHas('storage_assignment', [
                'item_id' => $this->item->item_id,
                'assignment_no' => $firstOperation === 'transfer' ? 2 : 1,
                'removed_at' => $returnTime,
            ]);
            $this->assertDatabaseHas('claim', [
                'item_id' => $this->item->item_id, 'claimant_id' => $this->people[0]->person_id,
                'outcome' => 'RETURNED', 'reviewed_by' => $this->staffUser->staff_id, 'reviewed_at' => $returnTime,
            ]);
        }
        if ($firstOperation === 'submit') {
            $this->assertSame(4, (clone $claims)->count());
            $this->assertDatabaseHas('claim', [
                'item_id' => $this->item->item_id, 'claimant_id' => $this->people[3]->person_id,
                'evidence_text' => 'Concurrent evidence', 'outcome' => $returned ? 'REJECTED' : 'PENDING',
            ]);
        } else {
            $this->assertSame(3, (clone $claims)->count());
        }
        if ($firstOperation === 'reject') {
            $this->assertDatabaseHas('claim', [
                'item_id' => $this->item->item_id, 'claimant_id' => $this->people[0]->person_id,
                'outcome' => 'REJECTED', 'reviewed_at' => '2026-09-30 12:00:00.654321',
            ]);
        }
    }

    private function startWorker(string $operation, bool $hold, string $clock): array
    {
        $url = match ($operation) {
            'return' => $this->reviewUrl('return'),
            'return-other' => $this->reviewUrl('return', 1),
            'reject' => $this->reviewUrl('reject'),
            'transfer' => '/api/items/'.$this->item->item_id.'/transfers',
            'submit' => '/api/items/'.$this->item->item_id.'/claims',
        };
        $body = match ($operation) {
            'transfer' => ['location_id' => $this->destination->location_id],
            'submit' => ['claimant_id' => $this->people[3]->person_id, 'evidence_text' => 'Concurrent evidence'],
            default => (object) [],
        };
        $input = new InputStream;
        $input->write(json_encode([
            'database' => DB::connection()->getConfig(), 'token' => $this->token,
            'url' => $url, 'body' => $body, 'clock' => $clock, 'hold' => $hold,
        ], JSON_THROW_ON_ERROR)."\n");
        $process = new Process([PHP_BINARY, base_path('tests/Support/concurrent-request.php')], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'reclaim_test_claims', 'DB_URL' => '',
        ], $input, 30);
        $this->workers[] = $process;
        $process->start();

        return [$process, $input];
    }

    private function waitForOutput(Process $process, string $marker): void
    {
        $deadline = microtime(true) + 10;
        do {
            if (str_contains($process->getOutput(), $marker)) {
                return;
            }
            if (! $process->isRunning()) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Worker did not reach '.$marker.'. '.$process->getErrorOutput().' '.$process->getOutput());
    }

    private function connectionId(Process $process): int
    {
        $this->assertSame(1, preg_match('/CONNECTION:(\d+)/', $process->getOutput(), $matches));

        return (int) $matches[1];
    }

    private function waitForDatabaseLockWait(int $waitingId, int $blockingId): void
    {
        $deadline = microtime(true) + 10;
        do {
            $wait = DB::selectOne(
                'SELECT COUNT(*) AS waiting
                 FROM performance_schema.data_lock_waits AS waits
                 JOIN performance_schema.threads AS requester ON requester.THREAD_ID = waits.REQUESTING_THREAD_ID
                 JOIN performance_schema.threads AS blocker ON blocker.THREAD_ID = waits.BLOCKING_THREAD_ID
                 WHERE requester.PROCESSLIST_ID = ? AND blocker.PROCESSLIST_ID = ?',
                [$waitingId, $blockingId]
            );
            if ((int) $wait->waiting > 0) {
                $this->assertGreaterThan(0, (int) $wait->waiting);

                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('MySQL did not observe the competing request waiting for the first request row lock.');
    }

    private function workerResult(Process $process): array
    {
        $this->assertSame(1, preg_match('/RESULT:(.+)/', $process->getOutput(), $matches));

        return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    }
}
