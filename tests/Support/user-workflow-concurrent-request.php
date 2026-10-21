<?php

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $input = json_decode(trim(fgets(STDIN)), true, 512, JSON_THROW_ON_ERROR);
    if (($input['database']['database'] ?? null) !== 'reclaim_test_claims'
        || ($input['database']['driver'] ?? null) !== 'mysql' || ! empty($input['database']['url'])) {
        throw new RuntimeException('Only the isolated MySQL test database is allowed.');
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    if (! $app->environment('testing')) throw new RuntimeException('Testing environment required.');
    config(['app.debug' => false, 'database.default' => 'mysql', 'database.connections.mysql' => $input['database'], 'cache.default' => 'array', 'session.driver' => 'array']);
    DB::purge('mysql');
    if (DB::selectOne('SELECT DATABASE() AS name')->name !== 'reclaim_test_claims') throw new RuntimeException('Wrong database.');
    DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
    CarbonImmutable::setTestNow(CarbonImmutable::parse($input['clock'], 'Asia/Manila'));
    echo 'CONNECTION:'.DB::selectOne('SELECT CONNECTION_ID() AS id')->id."\n"; fflush(STDOUT);
    $matchesLock = static fn (string $sql): bool => str_contains(strtolower($sql), 'from `'.strtolower($input['lock_table']).'`') && str_contains(strtolower($sql), 'for update');
    DB::connection()->beforeExecuting(function (string $sql) use ($matchesLock): void { if ($matchesLock($sql)) { echo "TRY_LOCK\n"; fflush(STDOUT); } });
    $held = false;
    DB::listen(function (QueryExecuted $query) use ($input, $matchesLock, &$held): void {
        if (! $held && $matchesLock($query->sql)) {
            $held = true; echo "LOCK_ACQUIRED\n"; fflush(STDOUT);
            if ($input['hold']) {
                echo "LOCKED\n"; fflush(STDOUT);
                if (trim(fgets(STDIN)) !== 'RELEASE') throw new RuntimeException('Barrier was not released.');
            }
        }
    });
    $request = Request::create($input['url'], $input['method'] ?? 'POST', [], [], [], [
        'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$input['token'],
    ], json_encode($input['body'] ?? [], JSON_THROW_ON_ERROR));
    $response = $kernel->handle($request);
    echo 'RESULT:'.json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)], JSON_THROW_ON_ERROR)."\n"; fflush(STDOUT);
    $kernel->terminate($request, $response);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Concurrent user workflow failed: '.get_class($exception)."\n");
    exit(1);
}