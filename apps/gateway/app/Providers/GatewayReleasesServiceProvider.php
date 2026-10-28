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
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\GatewayReleases\GatewayReleaseAgent;
use App\Domain\GatewayReleases\GatewayReleaseAutomation;
use App\Domain\GatewayReleases\GatewayReleaseDatabase;
use App\Domain\GatewayReleases\GatewayReleaseDrain;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseExecutionOwner;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseObserver;
use App\Domain\GatewayReleases\GatewayReleaseRuntime;
use App\Domain\GatewayReleases\GatewayReleaseSmoke;
use App\Domain\GatewayReleases\GatewayReleaseUnitConverger;
use App\Domain\GatewayReleases\GatewayReleaseUnitStarter;
use App\Domain\GatewayReleases\GatewayReleaseVerifier;
use App\Domain\GatewayReleases\GatewayReleaseWebBuild;
use App\Domain\GitHub\GreenCommitResolver;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Hibernation\RuntimeHibernatorConverger;
use App\Domain\Nodes\NodeUpdateBroadcaster;
use App\Domain\Settings\SettingRepository;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentContract;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentCommands\AgentTransports;
use App\Infrastructure\AgentCommands\NativeAgentCommandRunner;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Infrastructure\Gateway\GatewayApplicationPath;
use App\Infrastructure\Gateway\GatewayCheckoutAccessConverger;
use App\Infrastructure\Gateway\GatewayFpmConfigRenderer;
use App\Infrastructure\Gateway\GatewayHorizon;
use App\Infrastructure\Gateway\NativeGatewayFpmConverger;
use App\Infrastructure\GatewayReleases\ArtisanGatewayReleaseRuntime;
use App\Infrastructure\GatewayReleases\FilesystemGatewayReleaseObserver;
use App\Infrastructure\GatewayReleases\GatewayCleanupHandoff;
use App\Infrastructure\GatewayReleases\GatewayNodeAgentUpdate;
use App\Infrastructure\GatewayReleases\GatewayReleaseAdopter;
use App\Infrastructure\GatewayReleases\GatewayReleaseAdoptionFiles;
use App\Infrastructure\GatewayReleases\GatewayReleaseAgentDelivery;
use App\Infrastructure\GatewayReleases\GatewayReleaseAlerts;
use App\Infrastructure\GatewayReleases\GatewayReleaseBuilder;
use App\Infrastructure\GatewayReleases\GatewayReleaseCommands;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\GatewayReleases\GatewayReleasePromoter;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecorder;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecovery;
use App\Infrastructure\GatewayReleases\GatewayReleaseRestore;
use App\Infrastructure\GatewayReleases\GatewayReleaseRetry;
use App\Infrastructure\GatewayReleases\GatewayReleaseSource;
use App\Infrastructure\GatewayReleases\GatewayReleaseSupersession;
use App\Infrastructure\GatewayReleases\GatewayReleaseSwitcher;
use App\Infrastructure\GatewayReleases\GatewayRuntimeHandoff;
use App\Infrastructure\GatewayReleases\GatewaySchedulerHandoff;
use App\Infrastructure\GatewayReleases\GitHubArtifactWebBuild;
use App\Infrastructure\GatewayReleases\HttpGatewayReleaseVerifier;
use App\Infrastructure\GatewayReleases\LocalGatewayReleaseRuntime;
use App\Infrastructure\GatewayReleases\NativeGatewayReleaseExecutionOwner;
use App\Infrastructure\GatewayReleases\NativeGatewayReleaseUnitConverger;
use App\Infrastructure\GatewayReleases\ScriptGatewayReleaseSmoke;
use App\Infrastructure\GatewayReleases\SqliteGatewayReleaseDatabase;
use App\Infrastructure\GatewayReleases\SystemdGatewayReleaseDrain;
use App\Infrastructure\GatewayReleases\SystemdGatewayReleaseUnitStarter;
use App\Infrastructure\GitHub\GitHubActionsReader;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Releases\ReleaseProvenance;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Models\Node;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

/** Wires the Gateway release pipeline ([Update and recover a Gateway](/reference/gateway-recovery#release-layout)). */
final class GatewayReleasesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GatewayReleaseExecutionOwner::class, NativeGatewayReleaseExecutionOwner::class);
        $this->app->bind(GatewayReleaseDrain::class, SystemdGatewayReleaseDrain::class);
        $this->app->bind(GatewayReleaseLayout::class, static fn (): GatewayReleaseLayout => GatewayReleaseLayout::fromConfig());
        $this->app->bind(GatewayReleaseObserver::class, FilesystemGatewayReleaseObserver::class);
        $this->app->bind(
            GatewayReleaseWebBuild::class,
            static fn (Application $app): GitHubArtifactWebBuild => new GitHubArtifactWebBuild(
                layout: $app->make(GatewayReleaseLayout::class),
                commands: $app->make(GatewayReleaseCommands::class),
                actions: $app->make(GitHubActionsReader::class),
                webRoot: rtrim(Config::string('orbit.gateway_web'), '/'),
            ),
        );
        $this->app->bind(
            ScriptGatewayReleaseSmoke::class,
            static fn (Application $app): ScriptGatewayReleaseSmoke => new ScriptGatewayReleaseSmoke(
                layout: $app->make(GatewayReleaseLayout::class),
                processes: $app->make(ProcessRunner::class),
                origin: rtrim(Config::string('orbit.gateway_verify_origin'), '/'),
                webRoot: rtrim(Config::string('orbit.gateway_web'), '/'),
                timeoutSeconds: Config::integer('orbit.gateway_release_smoke_timeout'),
                writeCheckProject: Config::string('orbit.gateway_release_smoke_project'),
            ),
        );
        $this->app->bind(GatewayReleaseSmoke::class, ScriptGatewayReleaseSmoke::class);
        $this->app->bind(
            GatewayReleaseDatabase::class,
            static fn (Application $app): SqliteGatewayReleaseDatabase => new SqliteGatewayReleaseDatabase(
                commands: $app->make(GatewayReleaseCommands::class),
                layout: $app->make(GatewayReleaseLayout::class),
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
                commands: $app->make(GatewayReleaseCommands::class),
                stepLock: self::stepLock(),
            ),
        );
        $this->app->bind(
            GatewayRuntimeHandoff::class,
            static fn (Application $app): GatewayRuntimeHandoff => new GatewayRuntimeHandoff(
                builds: $app->make(NodeCaddyBuilds::class),
                fpmRenderer: $app->make(GatewayFpmConfigRenderer::class),
                fpm: $app->make(NativeGatewayFpmConverger::class),
                hibernator: $app->make(RuntimeHibernatorConverger::class),
                agentView: $app->make(AgentViewConverger::class),
                releaseUnits: $app->make(GatewayReleaseUnitConverger::class),
                scheduler: new GatewaySchedulerHandoff(
                    agents: $app->make(AgentCommandRunner::class),
                    applicationPath: GatewayApplicationPath::resolve(),
                    drainSeconds: self::drainSeconds(),
                ),
                cleanup: $app->make(GatewayCleanupHandoff::class),
                applicationPath: GatewayApplicationPath::resolve(),
                orbitHome: rtrim(Config::string('orbit.home'), '/'),
                fleet: $app->make(FleetConvergeUnits::class),
                agent: $app->make(GatewayNodeAgentUpdate::class),
                horizon: $app->make(GatewayHorizon::class),
                recipeImages: static function () use ($app): void {
                    $app->make(RecipeImageBuilds::class)->reconcile();
                },
            ),
        );
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
                runner: static fn (AgentDelivery $delivery): AgentCommandRunner => new NativeAgentCommandRunner($app->make(AgentContract::class), $delivery, $app->make(AgentTransports::class)),
            ),
        );
        $this->app->bind(
            GatewayReleaseBuilder::class,
            static fn (Application $app): GatewayReleaseBuilder => new GatewayReleaseBuilder(
                layout: $app->make(GatewayReleaseLayout::class),
                commands: $app->make(GatewayReleaseCommands::class),
                readAccess: $app->make(RepositoryReadAccess::class),
                web: $app->make(GatewayReleaseWebBuild::class),
                agent: $app->make(GatewayReleaseAgent::class),
                checkoutAccess: static function (string $application) use ($app): void {
                    $node = Node::query()->find($app->make(GatewayServingHost::class)->nodeId());
                    if (! $node instanceof Node) {
                        throw new GatewayReleaseException('access', 'gateway.release_gateway_node_missing', 'No serving Gateway Node exists to prepare checkout access.');
                    }
                    new GatewayCheckoutAccessConverger($app->make(AgentCommandRunner::class), $application)->converge($node);
                },
                minimumFreeBytes: max(0, Config::integer('orbit.gateway_releases.min_free_mb')) * 1_048_576,
                reservedBytes: static fn (): int => $app->make(GatewayReleaseDatabase::class)->snapshotBytes(),
                stepLock: self::stepLock(),
            ),
        );
        $this->app->bind(
            GatewayReleaseVerifier::class,
            static fn (): HttpGatewayReleaseVerifier => new HttpGatewayReleaseVerifier(
                origin: rtrim(Config::string('orbit.gateway_verify_origin'), '/'),
            ),
        );
        $this->app->bind(
            GatewayReleaseSwitcher::class,
            static fn (Application $app): GatewayReleaseSwitcher => new GatewayReleaseSwitcher(
                $app->make(GatewayReleaseLayout::class),
                $app->make(GatewayReleaseCommands::class),
            ),
        );
        $this->app->bind(
            GatewayReleaseSource::class,
            static fn (Application $app): GatewayReleaseSource => new GatewayReleaseSource(
                commands: $app->make(GatewayReleaseCommands::class),
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
                recovery: $app->make(GatewayReleaseRecovery::class),
                restore: $app->make(GatewayReleaseRestore::class),
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
        $this->app->bind(
            GatewayReleaseUnitConverger::class,
            static fn (Application $app): NativeGatewayReleaseUnitConverger => new NativeGatewayReleaseUnitConverger($app->make(GatewayReleaseCommands::class)),
        );
        $this->app->bind(
            GatewayReleaseUnitStarter::class,
            static fn (Application $app): SystemdGatewayReleaseUnitStarter => new SystemdGatewayReleaseUnitStarter($app->make(GatewayReleaseCommands::class)),
        );
        $this->app->bind(
            GatewayReleasePromoter::class,
            static fn (Application $app): GatewayReleasePromoter => new GatewayReleasePromoter(
                layout: $app->make(GatewayReleaseLayout::class),
                switcher: $app->make(GatewayReleaseSwitcher::class),
                runtime: $app->make(GatewayReleaseRuntime::class),
                verifier: $app->make(GatewayReleaseVerifier::class),
                web: $app->make(GatewayReleaseWebBuild::class),
                smoke: $app->make(GatewayReleaseSmoke::class),
                recorder: $app->make(GatewayReleaseRecorder::class),
                builder: $app->make(GatewayReleaseBuilder::class),
                keptReleases: Config::integer('orbit.gateway_releases.keep'),
                retry: $app->make(GatewayReleaseRetry::class),
                fleet: $app->make(FleetConvergeUnits::class),
            ),
        );
        $this->app->bind(
            GatewayReleaseAdopter::class,
            static fn (Application $app): GatewayReleaseAdopter => new GatewayReleaseAdopter(
                layout: $app->make(GatewayReleaseLayout::class),
                builder: $app->make(GatewayReleaseBuilder::class),
                files: $app->make(GatewayReleaseAdoptionFiles::class),
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
