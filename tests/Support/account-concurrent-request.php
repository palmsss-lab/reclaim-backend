<?php

use App\Actions\Accounts\AccountAdministration;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
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

    if (($input['operation'] ?? null) === 'bootstrap') {
        echo "READY\n";
        fflush(STDOUT);
        try {
            app(AccountAdministration::class)->bootstrapAdministrator($input['data']);
            echo 'RESULT:'.json_encode(['status' => 201], JSON_THROW_ON_ERROR)."\n";
        } catch (HttpException $exception) {
            echo 'RESULT:'.json_encode(['status' => $exception->getStatusCode()], JSON_THROW_ON_ERROR)."\n";
        }
        fflush(STDOUT);

        exit(0);
    }

    echo 'CONNECTION:'.DB::selectOne('SELECT CONNECTION_ID() AS id')->id."\n";
    fflush(STDOUT);
    $isAdminLock = static fn (string $sql): bool => str_starts_with($sql, 'select')
        && str_contains($sql, 'from `users`')
        && str_contains($sql, 'for update');
    DB::connection()->beforeExecuting(function (string $sql) use ($isAdminLock): void {
        if ($isAdminLock($sql)) {
            echo "TRY_LOCK\n";
            fflush(STDOUT);
        }
    });
    $held = false;
    DB::listen(function (QueryExecuted $query) use ($input, $isAdminLock, &$held): void {
        if (! $held && $isAdminLock($query->sql)) {
            $held = true;
            echo "LOCK_ACQUIRED\n";
            fflush(STDOUT);
            if ($input['hold']) {
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
    ], '{}');
    $response = $kernel->handle($request);
    echo 'RESULT:'.json_encode([
        'status' => $response->getStatusCode(),
        'body' => json_decode($response->getContent(), true),
    ], JSON_THROW_ON_ERROR)."\n";
    fflush(STDOUT);
    $kernel->terminate($request, $response);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Account concurrency worker failed: '.get_class($exception)."\n");
    exit(1);
}
