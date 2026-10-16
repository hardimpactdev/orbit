<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseExecutionOwner;
use App\Infrastructure\GatewayReleases\NativeGatewayReleaseExecutionOwner;
use App\Models\GatewayRelease;
use Illuminate\Support\Facades\Artisan;
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

it('refuses root and a different account at public mutation boundaries before locks or records', function (int $uid): void {
    app()->instance(GatewayReleaseExecutionOwner::class, new NativeGatewayReleaseExecutionOwner($this->pipeline->fixture->layout, static fn (): int => $uid, posix_geteuid()));
    $before = $this->pipeline->fixture->layout->currentReleaseId();
    foreach (['gateway:release:stage', 'gateway:release:deploy', 'gateway:release:finalize'] as $command) {
        $arguments = ['commit' => str_repeat('a', 40), ...($command === 'gateway:release:finalize' ? ['record' => '1'] : [])];
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
    file_put_contents($database, 'owned test database');
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
            'database' => ['default' => 'owner_test', 'connections' => ['owner_test' => ['database' => $database]]],
        ], true).';');
        chmod($cache, 0600);
        $owner = new NativeGatewayReleaseExecutionOwner($layout, accountUid: posix_geteuid());
        $owner->assertAllowed();
        expect(is_file($this->pipeline->home.'/gateway-release.lock'))->toBeFalse();
        chmod($cache, 0666);
        expect(fn () => $owner->assertAllowed())->toThrow(GatewayReleaseException::class)
            ->and(fileperms($cache) & 0777)->toBe(0666)
            ->and(is_file($this->pipeline->home.'/gateway-release.lock'))->toBeFalse();
    } finally {
        putenv($old === false ? 'APP_CONFIG_CACHE' : 'APP_CONFIG_CACHE='.$old);
        config(['database.default' => $oldDefault]);
    }
});
