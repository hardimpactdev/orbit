<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseDrain;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseExecutionOwner;
use App\Infrastructure\GatewayReleases\NativeGatewayReleaseExecutionOwner;
use App\Models\GatewayRelease;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\GatewayReleasePipeline;

pest()->group('subprocess');

beforeEach(function (): void {
    $this->pipeline = new GatewayReleasePipeline;
    $this->pipeline->adopt();
    $this->pipeline->bindLeaves();
});

afterEach(function (): void {
    $this->pipeline->cleanup();
});

it('refuses root and a different account at public release boundaries before observations locks or records', function (int $uid): void {
    app()->instance(GatewayReleaseExecutionOwner::class, new NativeGatewayReleaseExecutionOwner($this->pipeline->fixture->layout, static fn (): int => $uid, posix_geteuid()));
    $before = $this->pipeline->fixture->layout->currentReleaseId();
    foreach (['gateway:release:stage', 'gateway:release:deploy', 'gateway:release:finalize', 'gateway:release:abandon', 'gateway:release:inspect-process'] as $command) {
        $arguments = ['commit' => str_repeat('a', 40), ...(in_array($command, ['gateway:release:finalize', 'gateway:release:abandon'], true) ? ['record' => '1'] : []), ...($command === 'gateway:release:inspect-process' ? ['process' => '1'] : [])];
        $code = Artisan::call($command, $arguments);
        $output = Artisan::output();
        if (json_decode($output, true)['error_code'] !== 'gateway.release_owner_invalid') {
            throw new RuntimeException($output);
        }
        expect($code)->toBe(1)
            ->and(json_decode($output, true)['error_code'])->toBe('gateway.release_owner_invalid');
    }
    expect(glob($this->pipeline->home.'/*'))->toBe([])
        ->and(GatewayRelease::query()->count())->toBe(0)
        ->and($this->pipeline->fixture->layout->currentReleaseId())->toBe($before)
        ->and($this->pipeline->steps)->toBe([])
        ->and($this->pipeline->snapshots)->toBe(0);
})->with(['root' => 0, 'different account' => 2147483647]);

it('accepts the supported owner with matching private configuration and refuses drift without repair', function (): void {
    $layout = $this->pipeline->fixture->layout;
    $database = $this->pipeline->home.'/gateway.sqlite';
    (new PDO('sqlite:'.$database))->exec('CREATE TABLE marker (id INTEGER)');
    chmod($database, 0600);
    chmod($layout->environmentPath(), 0600);
    config(['database.default' => 'owner_test', 'database.connections.owner_test' => ['driver' => 'sqlite', 'database' => $database]]);
    $cache = $layout->applicationPath.'/bootstrap/cache/config.php';
    $old = getenv('APP_CONFIG_CACHE');
    $oldDefault = 'sqlite';
    putenv('APP_CONFIG_CACHE='.$cache);
    try {
        file_put_contents($cache, '<?php return '.var_export([
            'orbit' => ['home' => $this->pipeline->home, 'gateway_checkout' => $layout->applicationPath],
            'database' => ['default' => 'owner_test', 'connections' => ['owner_test' => ['driver' => 'sqlite', 'database' => $database]]],
        ], true).';');
        chmod($cache, 0600);
        $owner = new NativeGatewayReleaseExecutionOwner($layout, accountUid: posix_geteuid());
        $owner->assertAllowed();
        expect(is_file($this->pipeline->home.'/gateway-release.lock'))->toBeFalse();
        foreach (['gateway-release.lock', 'gateway-release-step.lock'] as $name) {
            $path = $this->pipeline->home.'/'.$name;
            foreach (['permissive', 'symlink'] as $fault) {
                if ($fault === 'symlink') {
                    symlink($database, $path);
                } else {
                    file_put_contents($path, 'preserved');
                    chmod($path, 0644);
                }
                $stat = lstat($path);
                app()->instance(GatewayReleaseExecutionOwner::class, $owner);
                expect(Artisan::call('gateway:release:stage', ['commit' => str_repeat('a', 40)]))->toBe(1);
                expect(json_decode(Artisan::output(), true)['error_code'])->toBe('gateway.release_owner_invalid');
                clearstatcache(true, $path);
                $after = lstat($path);
                // Path resolution can update symlink atime, which lstat reports under both keys.
                unset($stat[8], $stat['atime'], $after[8], $after['atime']);
                expect($after)->toBe($stat);
                if ($fault === 'symlink') {
                    expect(readlink($path))->toBe($database);
                } else {
                    expect(file_get_contents($path))->toBe('preserved');
                }
                expect($this->pipeline->snapshots)->toBe(0);
                expect($this->pipeline->steps)->toBe([]);
                unlink($path);
            }
        }
        chmod($cache, 0666);
        expect(fn () => $owner->assertAllowed())->toThrow(GatewayReleaseException::class)
            ->and(fileperms($cache) & 0777)->toBe(0666)
            ->and(is_file($this->pipeline->home.'/gateway-release.lock'))->toBeFalse();
    } finally {
        putenv($old === false ? 'APP_CONFIG_CACHE' : 'APP_CONFIG_CACHE='.$old);
        config(['database.default' => $oldDefault]);
    }
});

it('refuses divergent effective connections at public stage before any release work', function (string $change): void {
    $layout = $this->pipeline->fixture->layout;
    $database = $this->pipeline->home.'/declared.sqlite';
    $other = $this->pipeline->home.'/other.sqlite';
    foreach ([$database, $other] as $path) {
        (new PDO('sqlite:'.$path))->exec('CREATE TABLE marker (id INTEGER)');
        chmod($path, 0600);
    }
    chmod($layout->environmentPath(), 0600);
    $configuration = ['driver' => 'sqlite', 'database' => $database];
    $cached = $configuration;
    if ($change === 'running-url') {
        $configuration['url'] = 'sqlite:///'.$other;
    } elseif ($change === 'cached-url') {
        $cached['url'] = 'sqlite:///'.$other;
    } elseif ($change === 'cached-driver') {
        $cached['driver'] = 'mysql';
    } elseif ($change === 'read') {
        $configuration['read'] = ['database' => $other];
    } elseif ($change === 'write') {
        $configuration['write'] = ['database' => $other];
    } elseif ($change === 'retained-connection') {
        config(['database.connections.owner_drift' => ['driver' => 'sqlite', 'database' => $other]]);
        DB::connection('owner_drift')->getPdo();
    }
    $oldDefault = config('database.default');
    config(['database.default' => 'owner_drift', 'database.connections.owner_drift' => $configuration]);
    $cache = $layout->applicationPath.'/bootstrap/cache/config.php';
    $oldCache = getenv('APP_CONFIG_CACHE');
    putenv('APP_CONFIG_CACHE='.$cache);
    file_put_contents($cache, '<?php return '.var_export([
        'orbit' => ['home' => $this->pipeline->home, 'gateway_checkout' => $layout->applicationPath],
        'database' => ['default' => 'owner_drift', 'connections' => ['owner_drift' => $cached]],
    ], true).';');
    chmod($cache, 0600);
    app()->instance(GatewayReleaseExecutionOwner::class, new NativeGatewayReleaseExecutionOwner($layout, accountUid: posix_geteuid()));
    $before = $layout->currentReleaseId();
    $hashes = [hash_file('sha256', $database), hash_file('sha256', $other)];
    try {
        expect(Artisan::call('gateway:release:stage', ['commit' => str_repeat('a', 40)]))->toBe(1);
        expect(json_decode(Artisan::output(), true)['error_code'])->toBe('gateway.release_owner_invalid');
        expect(is_file($this->pipeline->home.'/gateway-release.lock'))->toBeFalse();
        expect($this->pipeline->snapshots)->toBe(0);
        expect($this->pipeline->steps)->toBe([]);
        expect($layout->currentReleaseId())->toBe($before);
        expect([hash_file('sha256', $database), hash_file('sha256', $other)])->toBe($hashes);
    } finally {
        config(['database.default' => $oldDefault]);
        DB::purge('owner_drift');
        putenv($oldCache === false ? 'APP_CONFIG_CACHE' : 'APP_CONFIG_CACHE='.$oldCache);
    }
    expect(GatewayRelease::query()->count())->toBe(0);
})->with(['running-url', 'cached-url', 'cached-driver', 'read', 'write', 'retained-connection']);

it('revalidates the refreshed target before snapshot or migration at the public stage boundary', function (): void {
    $layout = $this->pipeline->fixture->layout;
    $database = $this->pipeline->home.'/gateway.sqlite';
    $copy = new PDO('sqlite:'.$database);
    foreach (DB::select("SELECT sql FROM sqlite_master WHERE type = 'table' AND sql IS NOT NULL AND name NOT LIKE 'sqlite_%'") as $table) {
        $copy->exec($table->sql);
    }
    chmod($database, 0600);
    chmod($layout->environmentPath(), 0600);
    $oldDefault = config('database.default');
    config(['database.default' => 'refresh_owner', 'database.connections.refresh_owner' => ['driver' => 'sqlite', 'database' => $database]]);
    $values = [
        'orbit' => ['home' => $this->pipeline->home, 'gateway_checkout' => $layout->applicationPath],
        'database' => ['default' => 'refresh_owner', 'connections' => ['refresh_owner' => ['driver' => 'sqlite', 'database' => $database]]],
    ];
    $cache = $layout->applicationPath.'/bootstrap/cache/config.php';
    $oldCache = getenv('APP_CONFIG_CACHE');
    putenv('APP_CONFIG_CACHE='.$cache);
    file_put_contents($cache, '<?php return '.var_export($values, true).';');
    chmod($cache, 0600);
    // The child rebuilds its cache from shared state, which now selects another DB.
    $other = $this->pipeline->home.'/other.sqlite';
    (new PDO('sqlite:'.$other))->exec('CREATE TABLE preserve_me (id INTEGER)');
    chmod($other, 0600);
    $values['database']['connections']['refresh_owner']['database'] = $other;
    file_put_contents($layout->environmentPath(), json_encode($values, JSON_THROW_ON_ERROR));
    $this->pipeline->fixture->write('apps/gateway/artisan', <<<'PHP'
        <?php
        $cache = getenv('APP_CONFIG_CACHE') ?: __DIR__.'/bootstrap/cache/config.php';
        $values = json_decode(file_get_contents(__DIR__.'/.env'), true, flags: JSON_THROW_ON_ERROR);
        file_put_contents($cache, '<?php return '.var_export($values, true).';');
        PHP);
    $target = $this->pipeline->fixture->commit('shared configuration selects another database');
    app()->instance(GatewayReleaseExecutionOwner::class, new NativeGatewayReleaseExecutionOwner($layout, accountUid: posix_geteuid()));
    app()->instance(GatewayReleaseDrain::class, new class implements GatewayReleaseDrain
    {
        public function assertHeld(string $targetSha): void {}
    });
    $before = $layout->currentReleaseId();
    $hash = hash_file('sha256', $other);
    $mask = umask(0002);
    try {
        expect(Artisan::call('gateway:release:stage', ['commit' => $target]))->toBe(1);
        expect(json_decode(Artisan::output(), true)['error_code'])->toBe('gateway.release_owner_invalid');
        expect($this->pipeline->snapshots)->toBe(0);
        expect($this->pipeline->steps)->toBe([]);
        expect($layout->currentReleaseId())->toBe($before);
        expect(hash_file('sha256', $other))->toBe($hash);
        expect(is_file($this->pipeline->home.'/gateway-release.paused'))->toBeFalse();
        expect(GatewayRelease::query()->sole()->migrations_ran)->toBeFalse();
        expect(umask())->toBe(0002);
        foreach (['gateway-release.lock', 'gateway-release-step.lock'] as $name) {
            expect(fileperms($this->pipeline->home.'/'.$name) & 0777)->toBe(0600);
        }
        $targetCache = $layout->releaseApplicationPath(substr($target, 0, 12)).'/bootstrap/cache/config.php';
        expect(fileperms(dirname($targetCache)) & 0777)->toBe(0700);
        $refreshed = require $targetCache;
        expect($refreshed['database']['connections']['refresh_owner']['database'])->toBe($other);
        $refreshed['database']['connections']['refresh_owner']['database'] = $database;
        file_put_contents($targetCache, '<?php return '.var_export($refreshed, true).';');
        app(GatewayReleaseExecutionOwner::class)->assertAllowed($target);
        chmod(dirname($targetCache), 0775);
        expect(fn () => app(GatewayReleaseExecutionOwner::class)->assertAllowed($target))->toThrow(GatewayReleaseException::class);
        expect(fileperms(dirname($targetCache)) & 0777)->toBe(0775);
        expect($this->pipeline->snapshots)->toBe(0);
    } finally {
        umask($mask);
        config(['database.default' => $oldDefault]);
        DB::purge('refresh_owner');
        putenv($oldCache === false ? 'APP_CONFIG_CACHE' : 'APP_CONFIG_CACHE='.$oldCache);
    }
});
