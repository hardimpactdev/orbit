<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\Commands\AutomaticGatewayReleaseCommand;
use App\Console\Commands\ConfigureGatewayReleaseCommand;
use App\Console\Commands\ListGatewayReleasesCommand;
use App\Console\Commands\ResumeGatewayReleaseCleanupCommand;
use App\Console\Commands\ShowGatewayReleaseCommand;
use App\Console\Commands\WaitForGatewayReleaseIdleCommand;
use App\Console\Scheduling\GatewaySchedule;
use App\Domain\GatewayReleases\GatewayReleaseAgent;
use App\Domain\GatewayReleases\GatewayReleaseAutomation;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseObserver;
use App\Domain\GitHub\GreenCommitResolver;
use App\Domain\Nodes\NodeUpdateBroadcaster;
use App\Domain\Settings\SettingRepository;
use App\Infrastructure\GatewayReleases\FilesystemGatewayReleaseObserver;
use App\Infrastructure\GatewayReleases\GatewayReleaseAgentDelivery;
use App\Infrastructure\GatewayReleases\GatewayReleaseAlerts;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecorder;
use App\Infrastructure\GatewayReleases\GatewayReleaseRetry;
use App\Infrastructure\GatewayReleases\GatewayReleaseSource;
use App\Infrastructure\GatewayReleases\GatewayReleaseSupersession;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Releases\ReleaseProvenance;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

/** Wires the Gateway release records and admission ([Update and recover a Gateway](/reference/gateway-recovery#release-as-a-swamp-run)). */
final class GatewayReleasesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GatewayReleaseLayout::class, static fn (): GatewayReleaseLayout => GatewayReleaseLayout::fromConfig());
        $this->app->bind(GatewayReleaseObserver::class, FilesystemGatewayReleaseObserver::class);
        $this->app->singleton(
            GatewayReleaseLock::class,
            static fn (Application $app): GatewayReleaseLock => new GatewayReleaseLock(rtrim(Config::string('orbit.home'), '/').'/gateway-release.lock'),
        );
        $this->app->bind(
            GatewayReleaseAgent::class,
            static fn (Application $app): GatewayReleaseAgentDelivery => new GatewayReleaseAgentDelivery(
                processes: $app->make(ProcessRunner::class),
                provenance: $app->make(ReleaseProvenance::class),
                repository: Config::string('orbit.cli_releases.repository'),
                cacheDirectory: rtrim(Config::string('orbit.home'), '/').'/agent-artifacts',
            ),
        );
        $this->app->bind(
            GatewayReleaseSource::class,
            static fn (): GatewayReleaseSource => new GatewayReleaseSource(
                branch: Config::string('orbit.gateway_releases.branch'),
                checkName: Config::string('orbit.gateway_releases.check'),
            ),
        );
        $this->app->bind(
            GatewayReleaseSupersession::class,
            static fn (Application $app): GatewayReleaseSupersession => new GatewayReleaseSupersession(
                source: $app->make(GatewayReleaseSource::class),
                resolver: $app->make(GreenCommitResolver::class),
            ),
        );
        $this->app->bind(
            GatewayReleaseRecorder::class,
            static fn (Application $app): GatewayReleaseRecorder => new GatewayReleaseRecorder(
                alerts: $app->make(GatewayReleaseAlerts::class),
                automation: $app->make(GatewayReleaseAutomation::class),
                retry: $app->make(GatewayReleaseRetry::class),
                nodes: $app->make(NodeUpdateBroadcaster::class),
            ),
        );
        $this->app->bind(
            GatewayReleaseAutomation::class,
            static fn (Application $app): GatewayReleaseAutomation => new GatewayReleaseAutomation(
                settings: $app->make(SettingRepository::class),
                pauseMarker: rtrim(Config::string('orbit.home'), '/').'/gateway-release.paused',
            ),
        );
    }

    public function boot(): void
    {
        $this->commands([
            AutomaticGatewayReleaseCommand::class,
            ConfigureGatewayReleaseCommand::class,
            ResumeGatewayReleaseCleanupCommand::class,
            ListGatewayReleasesCommand::class,
            ShowGatewayReleaseCommand::class,
            WaitForGatewayReleaseIdleCommand::class,
        ]);
        // The tick only admits a run. Its bound covers admission's network reads, such as the 120-second agent
        // download; a release that switches restarts the scheduler. PRs merge straight into main, so the merges of
        // five minutes go out in one release of the newest green commit.
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            GatewaySchedule::command($schedule, 'gateway:release:auto', uniqueFor: 300, overlapMinutes: 5)->everyFiveMinutes();
        });
    }
}
