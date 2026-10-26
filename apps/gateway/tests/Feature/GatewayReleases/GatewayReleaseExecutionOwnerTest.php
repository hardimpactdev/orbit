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

beforeEach(function (): void {
    $this->pipeline = new GatewayReleasePipeline(adopted: true);
    $this->pipeline->adopt();
    $this->pipeline->bindLeaves();
    $this->oldDefault = config('database.default');
    $this->oldCache = getenv('APP_CONFIG_CACHE');
    $this->database = $this->pipeline->home.'/gateway.sqlite';
    $copy = new PDO('sqlite:'.$this->database);
    foreach (DB::select("SELECT sql FROM sqlite_master WHERE type = 'table' AND sql IS NOT NULL AND name NOT LIKE 'sqlite_%'") as $table) {
        $copy->exec($table->sql);
    }
    $this->configuration = ['driver' => 'sqlite', 'database' => $this->database];
    config(['database.default' => 'release_owner', 'database.connections.release_owner' => $this->configuration]);
    $this->cache = $this->pipeline->fixture->layout->applicationPath.'/bootstrap/cache/config.php';
    mkdir(dirname($this->cache), 0700, true);
    putenv('APP_CONFIG_CACHE='.$this->cache);
    $this->values = [
        'orbit' => ['home' => $this->pipeline->home, 'gateway_checkout' => $this->pipeline->fixture->layout->applicationPath],
        'database' => ['default' => 'release_owner', 'connections' => ['release_owner' => $this->configuration]],
    ];
    file_put_contents($this->cache, '<?php return '.var_export($this->values, true).';');
    chmod($this->cache, 0600);
    // The shared state the Gateway account owns in the release layout.
    $layout = $this->pipeline->fixture->layout;
    foreach ([$layout->sharedPath(), $layout->storagePath(), $layout->releasesPath()] as $directory) {
        mkdir($directory, 0700, true);
    }
    file_put_contents($layout->environmentPath(), "APP_KEY=base64:test\n");
    chmod($layout->environmentPath(), 0600);
    $this->owner = new NativeGatewayReleaseExecutionOwner($layout, accountUid: posix_geteuid());
    // The Gateway Node in the release database, for the release commands that run before the refusal.
    $this->pipeline->fixture->releaseCommands();
    app()->instance(GatewayReleaseExecutionOwner::class, $this->owner);
});

afterEach(function (): void {
    config(['database.default' => $this->oldDefault]);
    DB::purge('release_owner');
    putenv($this->oldCache === false ? 'APP_CONFIG_CACHE' : 'APP_CONFIG_CACHE='.$this->oldCache);
    $this->pipeline->cleanup();
});

it('refuses root and a different account at public boundaries before locks or records', function (int $uid): void {
    app()->instance(GatewayReleaseExecutionOwner::class, new NativeGatewayReleaseExecutionOwner($this->pipeline->fixture->layout, static fn (): int => $uid, posix_geteuid()));
    $before = $this->pipeline->fixture->currentId();
    foreach (['gateway:release:stage', 'gateway:release:deploy', 'gateway:release:finalize', 'gateway:release:abandon', 'gateway:release:inspect-process'] as $command) {
        $arguments = ['commit' => str_repeat('a', 40), ...(in_array($command, ['gateway:release:finalize', 'gateway:release:abandon'], true) ? ['record' => '1'] : []), ...($command === 'gateway:release:inspect-process' ? ['process' => '1'] : [])];
        expect(Artisan::call($command, $arguments))->toBe(1);
        expect(json_decode(Artisan::output(), true)['error_code'])->toBe('gateway.release_owner_invalid');
    }
    expect(is_file($this->pipeline->home.'/gateway-release.lock'))->toBeFalse();
    expect(is_file($this->pipeline->home.'/gateway-release-step.lock'))->toBeFalse();
    expect(GatewayRelease::query()->count())->toBe(0);
    expect($this->pipeline->fixture->currentId())->toBe($before);
    expect($this->pipeline->steps)->toBe([]);
    expect($this->pipeline->snapshots)->toBe(0);
})->with(['root' => 0, 'different account' => 2147483647]);

it('accepts the owner of the shared state without the agent and refuses drifted lock files without repair', function (): void {
    $this->owner->assertAllowed(str_repeat('a', 40));
    expect(is_file($this->pipeline->home.'/gateway-release.lock'))->toBeFalse();
    foreach (['gateway-release.lock', 'gateway-release-step.lock'] as $name) {
        $path = $this->pipeline->home.'/'.$name;
        foreach (['permissive', 'symlink'] as $fault) {
            if ($fault === 'symlink') {
                symlink($this->database, $path);
            } else {
                file_put_contents($path, 'preserved');
                chmod($path, 0644);
            }
            expect(fn () => $this->owner->assertAllowed())->toThrow(GatewayReleaseException::class);
            if ($fault === 'symlink') {
                expect(readlink($path))->toBe($this->database);
            } else {
                expect(file_get_contents($path))->toBe('preserved')->and(fileperms($path) & 0777)->toBe(0644);
            }
            unlink($path);
        }
    }
    chmod($this->pipeline->fixture->layout->environmentPath(), 0666);
    expect(fn () => $this->owner->assertAllowed())->toThrow(GatewayReleaseException::class)
        ->and(fileperms($this->pipeline->fixture->layout->environmentPath()) & 0777)->toBe(0666);
});

it('refuses shared state of the wrong kind without changing it', function (string $fault): void {
    $layout = $this->pipeline->fixture->layout;
    match ($fault) {
        'environment directory' => [unlink($layout->environmentPath()), mkdir($layout->environmentPath(), 0700)],
        'releases file' => [rmdir($layout->releasesPath()), file_put_contents($layout->releasesPath(), ''), chmod($layout->releasesPath(), 0600)],
    };

    expect(fn () => $this->owner->assertAllowed())->toThrow(GatewayReleaseException::class);
    expect($fault === 'environment directory' ? is_dir($layout->environmentPath()) : is_file($layout->releasesPath()))->toBeTrue();
})->with(['environment directory', 'releases file']);

it('refuses divergent connection or cached configuration before any release work', function (string $change): void {
    $other = $this->pipeline->home.'/other.sqlite';
    new PDO('sqlite:'.$other)->exec('CREATE TABLE preserve_me (id INTEGER)');
    $configuration = $this->configuration;
    $values = $this->values;
    match ($change) {
        'running-url' => $configuration['url'] = 'sqlite:///'.$other,
        'read' => $configuration['read'] = ['database' => $other],
        'write' => $configuration['write'] = ['database' => $other],
        'options' => $configuration['options'] = [PDO::ATTR_TIMEOUT => 5],
        'cached-url' => $values['database']['connections']['release_owner']['url'] = 'sqlite:///'.$other,
        'cached-driver' => $values['database']['connections']['release_owner']['driver'] = 'mysql',
        'cached-database' => $values['database']['connections']['release_owner']['database'] = $other,
        'cached-default' => $values['database']['default'] = 'other',
        'cached-home' => $values['orbit']['home'] = '/other',
        'cached-checkout' => $values['orbit']['gateway_checkout'] = '/other/apps/gateway',
        'retained-connection' => $configuration['database'] = $other,
        'retained-pdo' => DB::connection()->setPdo(new PDO('sqlite:'.$other)),
    };
    config(['database.connections.release_owner' => $configuration]);
    file_put_contents($this->cache, '<?php return '.var_export($values, true).';');
    chmod($this->cache, 0600);
    $before = $this->pipeline->fixture->currentId();
    $hashes = [hash_file('sha256', $this->database), hash_file('sha256', $other)];

    expect(Artisan::call('gateway:release:stage', ['commit' => str_repeat('a', 40)]))->toBe(1);
    expect(json_decode(Artisan::output(), true)['error_code'])->toBe('gateway.release_owner_invalid');
    expect(is_file($this->pipeline->home.'/gateway-release.lock'))->toBeFalse();
    expect($this->pipeline->snapshots)->toBe(0);
    expect($this->pipeline->steps)->toBe([]);
    expect($this->pipeline->fixture->currentId())->toBe($before);
    expect([hash_file('sha256', $this->database), hash_file('sha256', $other)])->toBe($hashes);
})->with(['running-url', 'cached-url', 'cached-driver', 'cached-database', 'cached-default', 'cached-home', 'cached-checkout', 'read', 'write', 'options', 'retained-connection', 'retained-pdo']);

it('rejects a missing or aliased configured database before the runner queries that connection', function (string $fault): void {
    $path = $this->pipeline->home.'/alternate.sqlite';
    if ($fault === 'alias') {
        symlink($this->database, $path);
    }
    config(['database.connections.release_owner.database' => $path]);
    DB::purge('release_owner');
    expect(fn () => $this->owner->assertAllowed())->toThrow(GatewayReleaseException::class);
    if ($fault === 'missing') {
        expect(file_exists($path))->toBeFalse();
    } else {
        expect(readlink($path))->toBe($this->database);
    }
})->with(['missing', 'alias']);

it('revalidates the refreshed target before snapshot or migration at the public stage boundary', function (): void {
    $layout = $this->pipeline->fixture->layout;
    $values = $this->values;
    $other = $this->pipeline->home.'/other.sqlite';
    new PDO('sqlite:'.$other)->exec('CREATE TABLE preserve_me (id INTEGER)');
    $values['database']['connections']['release_owner']['database'] = $other;
    $target = $this->pipeline->fixture->commit('shared configuration selects another database');
    $targetCache = $layout->releaseApplicationPath(substr($target, 0, 12)).'/bootstrap/cache/config.php';
    mkdir(dirname($targetCache), 0700, true);
    $refreshed = false;
    $this->pipeline->fixture->agents->answer('gateway.refresh-release-configuration', function (array $input) use ($target, $values, $targetCache, &$refreshed): array {
        expect($input['release'])->toBe(substr($target, 0, 12));
        file_put_contents($targetCache, '<?php return '.var_export($values, true).';');
        chmod($targetCache, 0600);
        $refreshed = true;

        return ['changed' => true];
    });
    app()->instance(GatewayReleaseDrain::class, new class implements GatewayReleaseDrain
    {
        public function assertHeld(string $targetSha): void {}
    });
    $before = $this->pipeline->fixture->currentId();
    $hash = hash_file('sha256', $other);

    expect(Artisan::call('gateway:release:stage', ['commit' => $target]))->toBe(1);
    expect(json_decode(Artisan::output(), true)['error_code'])->toBe('gateway.release_owner_invalid');
    expect($refreshed)->toBeTrue();
    expect($this->pipeline->snapshots)->toBe(0);
    expect($this->pipeline->steps)->toBe([]);
    expect($this->pipeline->fixture->currentId())->toBe($before);
    expect(hash_file('sha256', $other))->toBe($hash);
    expect(is_file($this->pipeline->home.'/gateway-release.paused'))->toBeFalse();
    expect(GatewayRelease::query()->sole()->migrations_ran)->toBeFalse();
    file_put_contents($targetCache, '<?php return '.var_export($this->values, true).';');
    $this->owner->assertAllowed($target);
});
