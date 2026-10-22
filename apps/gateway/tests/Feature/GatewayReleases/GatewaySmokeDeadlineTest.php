<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Infrastructure\GatewayReleases\ScriptGatewayReleaseSmoke;
use App\Models\GatewayRelease;
use Illuminate\Filesystem\Filesystem;
use Tests\Support\GatewayReleasePipeline;

require_once __DIR__.'/../../Support/GatewaySmokeFixtures.php';

pest()->group('subprocess');

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/orbit-smoke-deadline-'.bin2hex(random_bytes(12));
    mkdir($this->directory, 0700);
    config(['orbit.home' => $this->directory.'/home']);
    $this->app->useStoragePath($this->directory.'/storage');
    $this->beforeApplicationDestroyed(function (): void {
        new Filesystem()->deleteDirectory($this->directory);
    });
    $this->releaseId = 'abcdef012345';
    $this->sha = 'abcdef0123456789abcdef0123456789abcdef01';
});

it('stops a smoke run past its deadline and kills its process group', function (): void {
    $release = smoke_release($this->directory, $this->releaseId);
    file_put_contents($release.'/smoke-mode', 'hang');
    $layout = new GatewayReleaseLayout($this->directory.'/orbit/apps/gateway');
    $started = microtime(true);

    $exception = release_failure(fn () => smoke_runner($layout, timeout: 1, grace: 0)->run($this->releaseId, $this->sha));
    $this->assertFileExists($release.'/smoke-child');
    $child = (int) trim(file_get_contents($release.'/smoke-child'));

    expect($exception->step)->toBe('smoke')
        ->and($exception->errorCode)->toBe('gateway.release_smoke_timeout')
        ->and(microtime(true) - $started)->toBeLessThan(10.0)
        ->and($child)->toBeGreaterThan(1)
        ->and(posix_kill($child, 0))->toBeFalse();
});

it('keeps the terminated report from a smoke run it stopped', function (): void {
    $release = smoke_release($this->directory, $this->releaseId);
    file_put_contents($release.'/smoke-mode', 'terminated');
    $layout = new GatewayReleaseLayout($this->directory.'/orbit/apps/gateway');

    $exception = release_failure(fn () => smoke_runner($layout, timeout: 1, grace: 0)->run($this->releaseId, $this->sha));

    expect($exception->errorCode)->toBe('gateway.release_smoke_timeout')
        ->and($exception->phase['exit_code'])->toBe(124)
        ->and($exception->phase['report'])->toBe(['schema' => 1, 'passed' => false, 'error' => 'terminated']);
});

it('switches back when the release smoke command runs past its limit', function (): void {
    $pipeline = new GatewayReleasePipeline;
    $fixture = $pipeline->fixture;
    try {
        $first = substr($pipeline->adopt(), 0, 12);
        $fixture->write('bin/gateway-smoke', "#!/usr/bin/env bash\nsleep 60 &\nwait\n");
        chmod($fixture->origin.'/bin/gateway-smoke', 0755);
        $sha = $fixture->commit('Smoke hangs');
        $fixture->builder()->prepare($sha);
        $smoke = new ScriptGatewayReleaseSmoke(
            layout: $fixture->layout,
            processes: $fixture,
            origin: 'https://gateway.orbit',
            webRoot: $fixture->base.'/web',
            timeoutSeconds: 1,
            graceSeconds: 0,
        );

        $exception = release_failure(fn () => $pipeline->rollback($smoke)->execute(substr($sha, 0, 12)));
        $record = GatewayRelease::query()->firstOrFail();

        expect($exception->errorCode)->toBe('gateway.release_smoke_timeout')
            ->and($record->outcome)->toBe('switched_back')
            ->and($record->phases['smoke']['error_code'])->toBe('gateway.release_smoke_timeout')
            ->and($record->phases['smoke']['timeout_seconds'])->toBe(1)
            ->and($fixture->layout->currentReleaseId())->toBe($first);
    } finally {
        $fixture->cleanup();
    }
});
