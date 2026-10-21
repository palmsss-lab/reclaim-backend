<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AccountConcurrencyTest extends TestCase
{
    private array $workers = [];
    private array $createdUserIds = [];
    private array $createdStaffIds = [];
    private array $bootstrapEmails = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('reclaim_test_claims', DB::selectOne('SELECT DATABASE() AS name')->name);
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(0);
                }
            }
            $this->createdUserIds = array_merge(
                $this->createdUserIds,
                DB::table('users')->whereIn('email', $this->bootstrapEmails)->pluck('id')->all()
            );
            $this->createdStaffIds = array_merge(
                $this->createdStaffIds,
                DB::table('users')->whereIn('id', $this->createdUserIds)->whereNotNull('staff_id')->pluck('staff_id')->all()
            );
            if ($this->createdUserIds !== []) {
                DB::transaction(function (): void {
                    DB::table('personal_access_tokens')->where('tokenable_type', User::class)
                        ->whereIn('tokenable_id', $this->createdUserIds)->delete();
                    DB::table('sessions')->whereIn('user_id', $this->createdUserIds)->delete();
                    DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
                    DB::table('staff')->whereIn('staff_id', $this->createdStaffIds)->delete();
                });
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_competing_deactivations_cannot_remove_both_active_administrators(): void
    {
        [$adminA] = $this->makeAdmin('concurrent-a');
        [$adminB] = $this->makeAdmin('concurrent-b');
        $tokenA = $adminA->createToken('account-concurrency-a', ['staff'])->plainTextToken;
        $tokenB = $adminB->createToken('account-concurrency-b', ['staff'])->plainTextToken;

        [$first, $release] = $this->startRequest($tokenA, '/api/accounts/'.$adminB->id.'/deactivate', true);
        $this->waitForOutput($first, 'LOCKED');
        $blocking = $this->connectionId($first);
        [$second] = $this->startRequest($tokenB, '/api/accounts/'.$adminA->id.'/deactivate', false);
        $this->waitForOutput($second, 'TRY_LOCK');
        $this->waitForDatabaseLockWait($this->connectionId($second), $blocking);
        $this->assertStringNotContainsString('RESULT:', $second->getOutput());

        $release->write("RELEASE\n");
        $release->close();
        $first->wait();
        $second->wait();
        $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
        $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
        $this->assertSame(204, $this->workerResult($first)['status']);
        $this->assertSame(403, $this->workerResult($second)['status']);
        $this->assertSame(1, DB::table('users')->where('is_account_admin', true)->whereNull('deactivated_at')->count());
        $this->assertSame(1, DB::table('users')->where('is_account_admin', true)->whereNotNull('deactivated_at')->count());
    }

    public function test_concurrent_bootstrap_lock_creates_exactly_one_first_administrator(): void
    {
        $lockName = 'reclaim_account_admin_bootstrap';
        $this->assertSame(1, (int) DB::selectOne('SELECT GET_LOCK(?, 5) AS acquired', [$lockName])->acquired);
        $suffix = bin2hex(random_bytes(4));
        $this->bootstrapEmails = ['first-'.$suffix.'@example.test', 'second-'.$suffix.'@example.test'];
        $first = $this->startBootstrap('Bootstrap first-'.$suffix, $this->bootstrapEmails[0]);
        $second = $this->startBootstrap('Bootstrap second-'.$suffix, $this->bootstrapEmails[1]);
        $this->workers = [$first, $second];

        try {
            $this->waitForOutput($first, 'READY');
            $this->waitForOutput($second, 'READY');
            $this->assertTrue($first->isRunning());
            $this->assertTrue($second->isRunning());
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
        }

        $first->wait();
        $second->wait();
        $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
        $this->assertSame(0, $second->getExitCode(), $second->getErrorOutput());
        $statuses = [$this->workerResult($first)['status'], $this->workerResult($second)['status']];
        sort($statuses);
        $this->assertSame([201, 409], $statuses);
        $this->assertSame(1, DB::table('users')->whereIn('email', $this->bootstrapEmails)->where('is_account_admin', true)->count());
        $this->assertSame(1, DB::table('staff')->whereIn('full_name', [
            'Bootstrap first-'.$suffix, 'Bootstrap second-'.$suffix,
        ])->count());
        $this->assertStringNotContainsString('Bootstrap-test-password-123', $first->getOutput().$second->getOutput());
    }

    private function makeAdmin(string $prefix): array
    {
        $staff = Staff::create(['full_name' => 'Fictional '.$prefix]);
        $account = User::factory()->create(['password' => 'Admin-concurrent-password-123']);
        $account->staff()->associate($staff);
        $account->is_account_admin = true;
        $account->save();
        $this->createdUserIds[] = $account->id;
        $this->createdStaffIds[] = $staff->staff_id;

        return [$account, $staff];
    }

    private function startRequest(string $token, string $url, bool $hold): array
    {
        $stream = new InputStream;
        $stream->write(json_encode([
            'database' => DB::connection()->getConfig(),
            'token' => $token,
            'url' => $url,
            'hold' => $hold,
        ], JSON_THROW_ON_ERROR)."\n");
        $process = new Process([PHP_BINARY, base_path('tests/Support/account-concurrent-request.php')], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'reclaim_test_claims', 'DB_URL' => '',
        ], $stream, 30);
        $this->workers[] = $process;
        $process->start();

        return [$process, $stream];
    }

    private function startBootstrap(string $staffName, string $email): Process
    {
        $stream = new InputStream;
        $stream->write(json_encode([
            'database' => DB::connection()->getConfig(),
            'operation' => 'bootstrap',
            'data' => [
                'name' => 'First '.$staffName,
                'email' => $email,
                'staff_full_name' => $staffName,
                'password' => 'Bootstrap-test-password-123',
            ],
        ], JSON_THROW_ON_ERROR)."\n");
        $stream->close();
        $process = new Process([PHP_BINARY, base_path('tests/Support/account-concurrent-request.php')], base_path(), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'reclaim_test_claims', 'DB_URL' => '',
        ], $stream, 30);
        $process->start();

        return $process;
    }

    private function waitForOutput(Process $process, string $marker): void
    {
        $deadline = microtime(true) + 15;
        do {
            if (str_contains($process->getOutput(), $marker)) {
                return;
            }
            if (! $process->isRunning()) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Process did not reach '.$marker.'. '.$process->getErrorOutput().' '.$process->getOutput());
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
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('MySQL did not observe competing requests waiting for account administrator locks.');
    }

    private function workerResult(Process $process): array
    {
        $this->assertSame(1, preg_match('/RESULT:(.+)/', $process->getOutput(), $matches));

        return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    }
}
