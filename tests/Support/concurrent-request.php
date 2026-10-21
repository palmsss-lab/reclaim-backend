<?php

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    // Configuration and credentials arrive over stdin, never command-line arguments.
    $input = json_decode(trim(fgets(STDIN)), true, 512, JSON_THROW_ON_ERROR);
    if (($input['database']['database'] ?? null) !== 'reclaim_test_claims'
        || ($input['database']['driver'] ?? null) !== 'mysql'
        || ! empty($input['database']['url'])) {
        throw new RuntimeException('Only the isolated MySQL test database is allowed.');
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    if (! $app->environment('testing')) {
        throw new RuntimeException('The worker requires the testing environment.');
    }
    config([
        'app.debug' => false,
        'database.default' => 'mysql',
        'database.connections.mysql' => $input['database'],
        'cache.default' => 'array',
        'session.driver' => 'array',
    ]);
    DB::purge('mysql');
    if (DB::selectOne('SELECT DATABASE() AS name')->name !== 'reclaim_test_claims') {
        throw new RuntimeException('The connected database is not the test database.');
    }
    DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
    CarbonImmutable::setTestNow(CarbonImmutable::parse($input['clock'], 'Asia/Manila'));
    echo 'CONNECTION:'.DB::selectOne('SELECT CONNECTION_ID() AS id')->id."\n";
    fflush(STDOUT);

    $isParentLock = static fn (string $sql): bool => str_starts_with($sql, 'select')
        && str_contains($sql, 'from `found_item`')
        && str_contains($sql, 'for update');
    DB::connection()->beforeExecuting(function (string $sql) use ($isParentLock): void {
        if ($isParentLock($sql)) {
            echo "TRY_LOCK\n";
            fflush(STDOUT);
        }
    });
    $held = false;
    DB::listen(function (QueryExecuted $query) use ($input, $isParentLock, &$held): void {
        if (! $held && $isParentLock($query->sql)) {
            $held = true;
            echo "LOCK_ACQUIRED\n";
            fflush(STDOUT);
            if ($input['hold']) {
                // Test-only barrier after the real request has acquired the row lock.
                echo "LOCKED\n";
                fflush(STDOUT);
                if (trim(fgets(STDIN)) !== 'RELEASE') {
                    throw new RuntimeException('The parent did not release the request barrier.');
                }
            }
        }
    });

    $request = Request::create($input['url'], 'POST', [], [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$input['token'],
    ], json_encode($input['body'], JSON_THROW_ON_ERROR));
    $response = $kernel->handle($request);
    echo 'RESULT:'.json_encode([
        'status' => $response->getStatusCode(),
        'body' => json_decode($response->getContent(), true),
    ], JSON_THROW_ON_ERROR)."\n";
    fflush(STDOUT);
    $kernel->terminate($request, $response);
} catch (Throwable $exception) {
    // Do not dump credentials or SQL from process failures.
    fwrite(STDERR, 'Concurrent request worker failed: '.get_class($exception)."\n");
    exit(1);
}
