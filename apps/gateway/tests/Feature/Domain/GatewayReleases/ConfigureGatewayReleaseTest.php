<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\ConfigureGatewayReleaseAction;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseInventory;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseObserver;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

describe('configure a Gateway release', function (): void {
    beforeEach(function (): void {
        $this->base = sys_get_temp_dir().'/gateway-configure-'.bin2hex(random_bytes(8));
        $this->appPath = $this->base.'/orbit/apps/gateway';
        mkdir($this->appPath.'/bootstrap/cache', 0700, true);
        mkdir($this->base.'/.orbit', 0700);
        $this->sha = str_repeat('a', 40);
        $this->id = substr($this->sha, 0, 12);
        $this->observer = Mockery::mock(GatewayReleaseObserver::class);
        $this->layout = new GatewayReleaseLayout($this->appPath, $this->observer);
        $this->action = new ConfigureGatewayReleaseAction(new GatewayReleaseLock($this->base.'/.orbit/gateway-release.lock'), $this->layout);
        $this->cache = $this->appPath.'/bootstrap/cache/config.php';
        file_put_contents($this->cache, 'previous');
        file_put_contents($this->layout->containerInventoryPath(), '{}');
    });

    afterEach(function (): void {
        (new Filesystem)->deleteDirectory($this->base);
    });

    it('keeps configuring host worktree releases through the FPM transition', function (): void {
        unlink($this->layout->containerInventoryPath());
        $application = $this->layout->releaseApplicationPath($this->id);
        mkdir($application.'/bootstrap/cache', 0700, true);
        $this->observer->shouldReceive('inspect')->once()->with($this->layout, null)
            ->andReturn(new GatewayReleaseInventory($this->id, 'adopted', false, [$this->id => $this->sha], [], [$this->id]));
        Http::fake();
        Process::fake(function (PendingProcess $process) use ($application) {
            expect($process->path)->toBe($application)
                ->and($process->environment)->not->toHaveKey('ORBIT_GATEWAY_SSH_MUX')
                ->and($process->environment)->not->toHaveKey('ORBIT_GATEWAY_CONTAINER');
            file_put_contents($process->environment['APP_CONFIG_CACHE'], 'host');

            return Process::result();
        });

        expect($this->action->execute()['release'])->toBe($this->id)
            ->and(file_get_contents($application.'/bootstrap/cache/config.php'))->toBe('host');
        Http::assertNothingSent();
    });

    it('refuses configuration in a superseded release before building or touching the cache', function (): void {
        $this->observer->shouldReceive('inspect')->once()->with($this->layout, base_path())
            ->andReturn(new GatewayReleaseInventory($this->id, 'adopted', true, [$this->id => $this->sha], [], [$this->id]));
        Http::fake();
        Process::fake();
        expect(fn () => $this->action->execute())->toThrow(GatewayReleaseException::class, 'serving Gateway release');
        Http::assertNothingSent();
        Process::assertNothingRan();
        expect(file_get_contents($this->cache))->toBe('previous');
    });

    it('publishes a private cache in the serving checkout and reloads its workers', function (bool $reloadFails): void {
        $this->observer->shouldReceive('inspect')->once()->with($this->layout, base_path())
            ->andReturn(new GatewayReleaseInventory($this->id, 'adopted', false, [$this->id => $this->sha], [], [$this->id]));
        Process::fake(function (PendingProcess $process) {
            expect($process->path)->toBe($this->appPath)
                ->and($process->environment['ORBIT_GATEWAY_SSH_MUX'])->toBe('/run/orbit/ssh-mux')
                ->and($process->environment['ORBIT_GATEWAY_CONTAINER'])->toBe('1')
                ->and($process->environment['ORBIT_AGENT_DELIVERY'])->toBe($this->base.'/orbit/apps/agent/delivery/manifest.json');
            file_put_contents($process->environment['APP_CONFIG_CACHE'], 'next');

            return Process::result();
        });
        Http::fake(['http://localhost/frankenphp/workers/restart' => Http::sequence()->push('', $reloadFails ? 503 : 200)->push('')]);

        $marker = $this->layout->basePath().'/.gateway-env-digest';
        file_put_contents($marker, 'previous digest');

        if ($reloadFails) {
            expect(fn () => $this->action->execute())->toThrow(GatewayReleaseException::class, 'previous configuration was restored')
                ->and(file_get_contents($this->cache))->toBe('previous');
            Http::assertSentCount(2);
        } else {
            expect($this->action->execute()['sha'])->toBe($this->sha)
                ->and(file_get_contents($this->cache))->toBe('next')
                ->and(fileperms($this->cache) & 0777)->toBe(0600);
            Http::assertSentCount(1);
        }
        expect(glob($this->cache.'.next-*'))->toBe([])
            ->and(file_exists($marker))->toBeFalse();
    })->with([false, true]);
});
