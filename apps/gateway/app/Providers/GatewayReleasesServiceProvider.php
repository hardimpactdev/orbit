<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\GatewayReleases\DeployGatewayReleaseAction;
use App\Console\Commands\AbandonGatewayReleaseCommand;
use App\Console\Commands\AdoptGatewayReleaseCommand;
use App\Console\Commands\AutomaticGatewayReleaseCommand;
use App\Console\Commands\ConfigureGatewayReleaseCommand;
use App\Console\Commands\DeployGatewayReleaseCommand;
use App\Console\Commands\FinalizeGatewayReleaseCommand;
use App\Console\Commands\HandoffGatewayReleaseCommand;
use App\Console\Commands\InspectGatewayReleaseProcessCommand;
use App\Console\Commands\ListGatewayReleasesCommand;
use App\Console\Commands\PrepareGatewayReleaseCommand;
use App\Console\Commands\RollbackGatewayReleaseCommand;
use App\Console\Commands\RunGatewayReleaseCommand;
use App\Console\Commands\SettleGatewayReleaseCommand;
use App\Console\Commands\ShowGatewayReleaseCommand;
use App\Console\Commands\SmokeGatewayReleaseCommand;
use App\Console\Commands\StageGatewayReleaseCommand;
use App\Domain\AgentView\AgentViewConverger;
use App\Domain\Fleet\FleetConvergeUnits;
use App\Domain\GatewayReleases\GatewayReleaseAutomation;
use App\Domain\GatewayReleases\GatewayReleaseDatabase;
use App\Domain\GatewayReleases\GatewayReleaseDrain;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseRuntime;
use App\Domain\GatewayReleases\GatewayReleaseUnitConverger;
use App\Domain\GatewayReleases\GatewayReleaseUnitStarter;
use App\Domain\GatewayReleases\GatewayReleaseVerifier;
use App\Domain\GatewayReleases\GatewayReleaseWebBuild;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Hibernation\RuntimeHibernatorConverger;
use App\Domain\Nodes\NodeUpdateBroadcaster;
use App\Domain\Settings\SettingRepository;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskTickClock;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\Gateway\GatewayApplicationPath;
use App\Infrastructure\Gateway\GatewayFpmConfigRenderer;
use App\Infrastructure\Gateway\GatewayHorizon;
use App\Infrastructure\Gateway\GatewayQueueRedis;
use App\Infrastructure\Gateway\NativeGatewayFpmConverger;
use App\Infrastructure\GatewayReleases\ArtisanGatewayReleaseRuntime;
use App\Infrastructure\GatewayReleases\GatewayCleanupHandoff;
use App\Infrastructure\GatewayReleases\GatewayNodeAgentUpdate;
use App\Infrastructure\GatewayReleases\GatewayReleaseAdopter;
use App\Infrastructure\GatewayReleases\GatewayReleaseAlerts;
use App\Infrastructure\GatewayReleases\GatewayReleaseBuilder;
use App\Infrastructure\GatewayReleases\GatewayReleaseExchange;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecorder;
use App\Infrastructure\GatewayReleases\GatewayReleaseRetry;
use App\Infrastructure\GatewayReleases\GatewayReleaseSource;
use App\Infrastructure\GatewayReleases\GatewayReleaseTickConfirmation;
use App\Infrastructure\GatewayReleases\GatewayRuntimeHandoff;
use App\Infrastructure\GatewayReleases\GatewaySchedulerHandoff;
use App\Infrastructure\GatewayReleases\LocalGatewayReleaseRuntime;
use App\Infrastructure\GatewayReleases\SqliteGatewayReleaseDatabase;
use App\Infrastructure\GatewayReleases\SystemdGatewayReleaseDrain;
use App\Infrastructure\GatewayReleases\SystemdGatewayReleaseUnitStarter;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

/** Wires the Gateway release pipeline ([Update and recover a Gateway](/reference/gateway-recovery#release-layout)). */
final class GatewayReleasesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // What stays here is a factory with logic, or an abstract that a constructor gives a default (see
        // ApplicationServiceProvider). The other release classes declare their wiring with container attributes.
        $this->app->bind(GatewayReleaseDrain::class, SystemdGatewayReleaseDrain::class);
        $this->app->bind(GatewayReleaseLayout::class, static fn (): GatewayReleaseLayout => GatewayReleaseLayout::fromConfig());
        $this->app->bind(
            GatewayReleaseDatabase::class,
            static fn (Application $app): SqliteGatewayReleaseDatabase => new SqliteGatewayReleaseDatabase(
                processes: $app->make(ProcessRunner::class),
                orbitHome: rtrim(Config::string('orbit.home'), '/'),
                keptSnapshots: max(1, Config::integer('orbit.gateway_releases.snapshots_keep')),
                minimumFreeBytes: max(0, Config::integer('orbit.gateway_releases.min_free_mb')) * 1_048_576,
                stepLock: self::stepLock(),
            ),
        );
        $this->app->bind(
            GatewayReleaseRuntime::class,
            static fn (Application $app): ArtisanGatewayReleaseRuntime => new ArtisanGatewayReleaseRuntime(
                layout: $app->make(GatewayReleaseLayout::class),
                processes: $app->make(ProcessRunner::class),
                // The scheduler drain, a forced stop's wait for the tick lock, the rest of the handoff, and the Gateway
                // Node's agent update.
                timeout: (float) (self::drainSeconds() + 330 + 600 + GatewayNodeAgentUpdate::TimeoutSeconds),
                stepLock: self::stepLock(),
                fallback: new LocalGatewayReleaseRuntime($app->make(GatewayRuntimeHandoff::class)),
            ),
        );
        $this->app->bind(
            GatewayRuntimeHandoff::class,
            static fn (Application $app): GatewayRuntimeHandoff => new GatewayRuntimeHandoff(
                builds: $app->make(NodeCaddyBuilds::class),
                fpmRenderer: $app->make(GatewayFpmConfigRenderer::class),
                fpm: $app->make(NativeGatewayFpmConverger::class),
                files: $app->make(ProtectedFileWriter::class),
                hibernator: $app->make(RuntimeHibernatorConverger::class),
                agentView: $app->make(AgentViewConverger::class),
                releaseUnits: $app->make(GatewayReleaseUnitConverger::class),
                scheduler: new GatewaySchedulerHandoff(
                    processes: $app->make(ProcessRunner::class),
                    applicationPath: GatewayApplicationPath::resolve(),
                    drainSeconds: self::drainSeconds(),
                ),
                cleanup: $app->make(GatewayCleanupHandoff::class),
                applicationPath: GatewayApplicationPath::resolve(),
                orbitHome: rtrim(Config::string('orbit.home'), '/'),
                fleet: $app->make(FleetConvergeUnits::class),
                agent: new GatewayNodeAgentUpdate($app->make(ProcessRunner::class)),
                horizon: $app->make(GatewayHorizon::class),
                recipeImages: static function () use ($app): void {
                    $app->make(RecipeImageBuilds::class)->reconcile();
                },
            ),
        );
        $this->app->singleton(
            GatewayReleaseLock::class,
            static fn (): GatewayReleaseLock => new GatewayReleaseLock(rtrim(Config::string('orbit.home'), '/').'/gateway-release.lock'),
        );
        $this->app->bind(
            GatewayReleaseBuilder::class,
            static fn (Application $app): GatewayReleaseBuilder => new GatewayReleaseBuilder(
                layout: $app->make(GatewayReleaseLayout::class),
                processes: $app->make(ProcessRunner::class),
                readAccess: $app->make(RepositoryReadAccess::class),
                web: $app->make(GatewayReleaseWebBuild::class),
                minimumFreeBytes: max(0, Config::integer('orbit.gateway_releases.min_free_mb')) * 1_048_576,
                reservedBytes: static fn (): int => $app->make(GatewayReleaseDatabase::class)->snapshotBytes(),
                stepLock: self::stepLock(),
            ),
        );
        $this->app->bind(
            GatewayReleaseSource::class,
            static fn (Application $app): GatewayReleaseSource => new GatewayReleaseSource(
                processes: $app->make(ProcessRunner::class),
                branch: Config::string('orbit.gateway_releases.branch'),
                checkName: Config::string('orbit.gateway_releases.check'),
            ),
        );
        $this->app->bind(
            GatewayReleaseRecorder::class,
            static fn (Application $app): GatewayReleaseRecorder => new GatewayReleaseRecorder(
                alerts: $app->make(GatewayReleaseAlerts::class),
                automation: $app->make(GatewayReleaseAutomation::class),
                units: $app->make(GatewayReleaseUnitStarter::class),
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
        $this->app->bind(GatewayReleaseUnitStarter::class, SystemdGatewayReleaseUnitStarter::class);
        $this->app->bind(
            GatewayReleaseTickConfirmation::class,
            static fn (Application $app): GatewayReleaseTickConfirmation => new GatewayReleaseTickConfirmation(
                clock: $app->make(TaskTickClock::class),
                extension: $app->make(TaskExtensionState::class),
                alerts: $app->make(GatewayReleaseAlerts::class),
                windowSeconds: Config::integer('orbit.gateway_releases.tick_confirmation_seconds'),
                horizonRunning: GatewayQueueRedis::configured()
                    ? static fn (): bool => $app->make(MasterSupervisorRepository::class)->all() !== []
                    : null,
                horizonWaitSeconds: GatewayHorizon::longestJobSeconds(),
            ),
        );
        $this->app->bind(
            GatewayReleaseAdopter::class,
            static fn (Application $app): GatewayReleaseAdopter => new GatewayReleaseAdopter(
                layout: $app->make(GatewayReleaseLayout::class),
                processes: $app->make(ProcessRunner::class),
                builder: $app->make(GatewayReleaseBuilder::class),
                exchange: new GatewayReleaseExchange($app->make(ProcessRunner::class)),
                runtime: new LocalGatewayReleaseRuntime($app->make(GatewayRuntimeHandoff::class)),
                verifier: $app->make(GatewayReleaseVerifier::class),
                recorder: $app->make(GatewayReleaseRecorder::class),
                deploy: $app->make(DeployGatewayReleaseAction::class),
            ),
        );
    }

    public function boot(): void
    {
        $this->commands([
            AbandonGatewayReleaseCommand::class,
            AdoptGatewayReleaseCommand::class,
            AutomaticGatewayReleaseCommand::class,
            ConfigureGatewayReleaseCommand::class,
            DeployGatewayReleaseCommand::class,
            FinalizeGatewayReleaseCommand::class,
            HandoffGatewayReleaseCommand::class,
            InspectGatewayReleaseProcessCommand::class,
            ListGatewayReleasesCommand::class,
            PrepareGatewayReleaseCommand::class,
            RollbackGatewayReleaseCommand::class,
            RunGatewayReleaseCommand::class,
            SettleGatewayReleaseCommand::class,
            ShowGatewayReleaseCommand::class,
            SmokeGatewayReleaseCommand::class,
            StageGatewayReleaseCommand::class,
        ]);
    }

    private static function drainSeconds(): int
    {
        return max(0, Config::integer('orbit.gateway_releases.scheduler_drain_seconds'));
    }

    private static function stepLock(): string
    {
        return rtrim(Config::string('orbit.home'), '/').'/gateway-release-step.lock';
    }
}
