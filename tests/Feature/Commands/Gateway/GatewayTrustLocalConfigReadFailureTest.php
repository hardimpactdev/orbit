<?php

declare(strict_types=1);

use App\Services\Trust\TrustStoreInstaller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Saloon\Http\Faking\MockClient;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    Artisan::call('migrate:fresh', ['--force' => true]);

    $this->tempStorage = sys_get_temp_dir().'/orbit-test-storage-'.uniqid();
    app()->useStoragePath($this->tempStorage);

    $this->fakeInstaller = new class implements TrustStoreInstaller
    {
        public bool $isTrustedCalled = false;

        /** @var list<array{path: string, label: string}> */
        public array $trustCalls = [];

        public function isCaTrusted(string $rootCaPath, string $label): bool
        {
            $this->isTrustedCalled = true;

            return false;
        }

        public function trustCa(string $rootCaPath, string $label, ?Closure $log = null): void
        {
            $this->trustCalls[] = ['path' => $rootCaPath, 'label' => $label];
        }
    };

    app()->instance(TrustStoreInstaller::class, $this->fakeInstaller);
    fakeGatewayCaRootThroughLaravelHttp();
});

afterEach(function (): void {
    MockClient::destroyGlobal();

    if (isset($this->tempStorage) && File::isDirectory($this->tempStorage)) {
        File::deleteDirectory($this->tempStorage);
    }
});

it('emits local config read failure before side effects for each actionable reason', function (string $reason, string $message, Closure $arrange): void {
    Http::fake(fn () => throw new RuntimeException('gateway:trust must fail before HTTP side effects'));

    $cleanup = $arrange();

    try {
        $output = runGatewayTrustLocalConfigReadFailureJson();
    } finally {
        if ($cleanup instanceof Closure) {
            $cleanup();
        }
    }

    expect($output['error']['code'])->toBe('node.local_config_read_failed')
        ->and($output['error']['message'])->toBe($message)
        ->and($output['error']['meta']['field'])->toBe('gateway')
        ->and($output['error']['meta']['reason'])->toBe($reason)
        ->and($this->fakeInstaller->isTrustedCalled)->toBeFalse()
        ->and($this->fakeInstaller->trustCalls)->toBeEmpty()
        ->and(File::exists(storage_path('app/orbit/gateway-ca/orbit.crt')))->toBeFalse();

    Http::assertNothingSent();
})->with([
    'database unavailable' => [
        'local_database_unavailable',
        'Local Orbit database is unavailable. Check the database file path and permissions.',
        fn (): Closure => useGatewayTrustLocalConfigDatabasePath(sys_get_temp_dir().'/orbit-missing-dir-'.uniqid().'/database.sqlite'),
    ],
    'database locked' => [
        'local_database_locked',
        'Local Orbit database is locked. Another Orbit process may be writing; try again.',
        fn (): Closure => useLockedGatewayTrustLocalConfigDatabase(),
    ],
    'database read only' => [
        'local_database_read_only',
        'Local Orbit database is read-only. Check the database file permissions.',
        function (): Closure {
            DB::table('local_gateway_settings')->delete();
            DB::statement('PRAGMA query_only = ON');

            return fn (): mixed => DB::statement('PRAGMA query_only = OFF');
        },
    ],
    'database corrupt' => [
        'local_database_corrupt',
        'Local Orbit database file is corrupt or unreadable.',
        function (): Closure {
            $path = sys_get_temp_dir().'/orbit-corrupt-db-'.uniqid().'.sqlite';
            File::put($path, 'not a sqlite database');

            return useGatewayTrustLocalConfigDatabasePath($path);
        },
    ],
    'settings table missing' => [
        'settings_table_missing',
        'Local gateway settings table is missing. Run database migrations.',
        function (): null {
            Schema::dropIfExists('local_gateway_settings');

            return null;
        },
    ],
]);

it('shows actionable human prose for each local config read failure reason', function (string $reason, string $message, Closure $arrange): void {
    Http::fake(fn () => throw new RuntimeException('gateway:trust must fail before HTTP side effects'));

    $cleanup = $arrange();

    try {
        $this->artisan('gateway:trust')
            ->expectsOutputToContain($message)
            ->assertFailed();
    } finally {
        if ($cleanup instanceof Closure) {
            $cleanup();
        }
    }

    expect($this->fakeInstaller->isTrustedCalled)->toBeFalse()
        ->and($this->fakeInstaller->trustCalls)->toBeEmpty()
        ->and(File::exists(storage_path('app/orbit/gateway-ca/orbit.crt')))->toBeFalse();

    Http::assertNothingSent();
})->with([
    'database unavailable' => [
        'local_database_unavailable',
        'Local Orbit database is unavailable. Check the database file path and permissions.',
        fn (): Closure => useGatewayTrustLocalConfigDatabasePath(sys_get_temp_dir().'/orbit-missing-dir-'.uniqid().'/database.sqlite'),
    ],
    'database locked' => [
        'local_database_locked',
        'Local Orbit database is locked. Another Orbit process may be writing; try again.',
        fn (): Closure => useLockedGatewayTrustLocalConfigDatabase(),
    ],
    'database read only' => [
        'local_database_read_only',
        'Local Orbit database is read-only. Check the database file permissions.',
        function (): Closure {
            DB::table('local_gateway_settings')->delete();
            DB::statement('PRAGMA query_only = ON');

            return fn (): mixed => DB::statement('PRAGMA query_only = OFF');
        },
    ],
    'database corrupt' => [
        'local_database_corrupt',
        'Local Orbit database file is corrupt or unreadable.',
        function (): Closure {
            $path = sys_get_temp_dir().'/orbit-corrupt-db-'.uniqid().'.sqlite';
            File::put($path, 'not a sqlite database');

            return useGatewayTrustLocalConfigDatabasePath($path);
        },
    ],
    'settings table missing' => [
        'settings_table_missing',
        'Local gateway settings table is missing. Run database migrations.',
        function (): null {
            Schema::dropIfExists('local_gateway_settings');

            return null;
        },
    ],
]);

function runGatewayTrustLocalConfigReadFailureJson(): array
{
    $output = new BufferedOutput;
    Artisan::call('gateway:trust', ['--json' => true], $output);

    return json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);
}

function useGatewayTrustLocalConfigDatabasePath(string $path): Closure
{
    $originalDatabase = config('database.connections.sqlite.database');

    DB::purge('sqlite');
    config(['database.connections.sqlite.database' => $path]);
    DB::reconnect('sqlite');

    return function () use ($originalDatabase): void {
        DB::purge('sqlite');
        config(['database.connections.sqlite.database' => $originalDatabase]);
        DB::reconnect('sqlite');
    };
}

function useLockedGatewayTrustLocalConfigDatabase(): Closure
{
    $path = sys_get_temp_dir().'/orbit-locked-db-'.uniqid().'.sqlite';
    $pdo = new PDO("sqlite:{$path}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = DELETE');
    $pdo->exec('CREATE TABLE local_gateway_settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
        gateway_url VARCHAR(255) DEFAULT NULL,
        gateway_wg_ip VARCHAR(255) DEFAULT NULL,
        ca_sha256 VARCHAR(255) DEFAULT NULL,
        ca_pem_path VARCHAR(255) DEFAULT NULL,
        trusted_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT NULL,
        updated_at DATETIME DEFAULT NULL
    )');
    $pdo->exec('BEGIN EXCLUSIVE');

    $restoreDatabase = useGatewayTrustLocalConfigDatabasePath($path);
    $connection = DB::connection('sqlite');
    $connection->getPdo()->exec('PRAGMA busy_timeout = 1');

    return function () use ($pdo, $restoreDatabase): void {
        $pdo->exec('COMMIT');
        $restoreDatabase();
    };
}
