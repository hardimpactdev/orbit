<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Actions\Instances\DestroyInstanceAction;
use App\Actions\Instances\SynchronizeInstanceEnvironmentAction;
use App\Console\Commands\CheckInstanceDeploymentOperationsCommand;
use App\Console\Scheduling\GatewaySchedule;
use App\Domain\Instances\DatabaseClone\InstanceSqliteCloner;
use App\Domain\Instances\Dependencies\SwampDependencyReader;
use App\Domain\Instances\Deployment\AutomaticDeployPolicyWriter;
use App\Domain\Instances\Deployment\InstanceDeploymentSecrets;
use App\Domain\Instances\Deployment\ProductionDeployment;
use App\Domain\Instances\DevelopmentInstanceBranchInspector;
use App\Domain\Instances\DevelopmentInstanceConfigurator;
use App\Domain\Instances\DevelopmentInstanceProvisioner;
use App\Domain\Instances\DevelopmentInstanceSourceLifecycle;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\DevelopmentSourceAccess;
use App\Domain\Instances\Environment\EnvironmentSyncSecrets;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentReader;
use App\Domain\Instances\Environment\InstanceEnvironmentRuntimeRefresher;
use App\Domain\Instances\Environment\InstanceEnvironmentSynchronizer;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Instances\Environment\InstanceRouteEnvironmentSynchronizer;
use App\Domain\Instances\Environment\InstanceTestEnvironmentWriter;
use App\Domain\Instances\InstanceCloneCandidateInspector;
use App\Domain\Instances\InstanceDestinationGuard;
use App\Domain\Instances\InstanceLifecycleGitSecret;
use App\Domain\Instances\InstanceLifecycleUrlSecret;
use App\Domain\Instances\InstanceRemover;
use App\Domain\Instances\Logs\InstanceLogReader;
use App\Domain\Instances\ProductionCloneRouteProjector;
use App\Domain\Instances\ProductionInstanceProvisioner;
use App\Domain\Instances\ProductionInstanceSourceLifecycle;
use App\Domain\Instances\ProductionPhpRuntimeManager;
use App\Domain\Instances\ProductionReleaseLayout;
use App\Domain\Instances\ProductionRepositoryBinding;
use App\Domain\Instances\ProductionRouteProjector;
use App\Domain\Instances\ProductionWebRootManager;
use App\Domain\Instances\Queue\InstanceQueueReader;
use App\Domain\Instances\Registration\RegistrationSourceManager;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceFinalizer;
use App\Domain\Instances\Removal\DevelopmentInstanceSourceRemoval;
use App\Domain\Instances\Removal\InstanceRemovalProjector;
use App\Domain\Instances\Removal\ProductionInstanceSourceRemoval;
use App\Domain\Instances\RouteApplicationUrlWriter;
use App\Domain\Instances\Sqlite\InstanceSqliteSeeder;
use App\Domain\Instances\Sqlite\SqliteSnapshotTransfer;
use App\Domain\Instances\StepLists\InstanceStepListSecrets;
use App\Domain\Instances\Transfer\InstanceTransferRouteProjector;
use App\Domain\Instances\Transfer\InstanceTransferRuntime;
use App\Domain\Instances\Transfer\InstanceTransferSource;
use App\Domain\Instances\VpEnvironment\InstanceVpEnvironmentSecrets;
use App\Domain\Operations\OperationSecretSource;
use App\Http\Controllers\Api\OperationExecutorController;
use App\Http\Middleware\Instances\SerializeInstanceEnvironment;
use App\Infrastructure\AgentView\StreamWebSocketClient;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Dependencies\WebSocketSwampDependencyReader;
use App\Infrastructure\Instances\NativeDevelopmentInstanceProvisioner;
use App\Infrastructure\Instances\NativeDevelopmentRouteProjector;
use App\Infrastructure\Instances\NativeDevelopmentSourceAccess;
use App\Infrastructure\Instances\NativeInstanceEnvironmentOperationLock;
use App\Infrastructure\Instances\NativeInstanceRemovalProjector;
use App\Infrastructure\Instances\NativeInstanceTransferRuntime;
use App\Infrastructure\Instances\NativeProductionInstanceProvisioner;
use App\Infrastructure\Instances\NativeProductionRouteProjector;
use App\Infrastructure\Instances\PlacementRouteApplicationUrlWriter;
use App\Infrastructure\Instances\ProtectedSqliteSnapshotTransfer;
use App\Infrastructure\Instances\RecordedProductionInstanceSourceRemoval;
use App\Infrastructure\Instances\RemoteDevelopmentInstanceConfigurator;
use App\Infrastructure\Instances\RemoteDevelopmentInstanceSourceLifecycle;
use App\Infrastructure\Instances\RemoteDevelopmentInstanceSourceRemoval;
use App\Infrastructure\Instances\RemoteInstanceCloneCandidateInspector;
use App\Infrastructure\Instances\RemoteInstanceDestinationGuard;
use App\Infrastructure\Instances\RemoteInstanceEnvironmentAccess;
use App\Infrastructure\Instances\RemoteInstanceEnvironmentRuntimeRefresher;
use App\Infrastructure\Instances\RemoteInstanceLogReader;
use App\Infrastructure\Instances\RemoteInstanceQueueReader;
use App\Infrastructure\Instances\RemoteInstanceSqliteCloner;
use App\Infrastructure\Instances\RemoteInstanceSqliteSeeder;
use App\Infrastructure\Instances\RemoteInstanceTransferSource;
use App\Infrastructure\Instances\RemoteProductionDeployment;
use App\Infrastructure\Instances\RemoteProductionInstanceSourceLifecycle;
use App\Infrastructure\Instances\RemoteProductionPhpRuntimeManager;
use App\Infrastructure\Instances\RemoteProductionRepositoryBinding;
use App\Infrastructure\Instances\RemoteProductionWebRootManager;
use App\Infrastructure\Instances\RemoteRegistrationSourceManager;
use App\Infrastructure\Processes\CommandDeadline;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class InstancesServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        InstanceDestinationGuard::class => RemoteInstanceDestinationGuard::class,
        InstanceCloneCandidateInspector::class => RemoteInstanceCloneCandidateInspector::class,
        InstanceEnvironmentReader::class => RemoteInstanceEnvironmentAccess::class,
        InstanceEnvironmentWriter::class => RemoteInstanceEnvironmentAccess::class,
        InstanceTestEnvironmentWriter::class => RemoteInstanceEnvironmentAccess::class,
        InstanceSqliteCloner::class => RemoteInstanceSqliteCloner::class,
        InstanceOperationPreflight::class => RemoteInstanceEnvironmentAccess::class,
        InstanceSqliteSeeder::class => RemoteInstanceSqliteSeeder::class,
        InstanceTransferSource::class => RemoteInstanceTransferSource::class,
        InstanceTransferRuntime::class => NativeInstanceTransferRuntime::class,
        InstanceTransferRouteProjector::class => NativeDevelopmentRouteProjector::class,
        DevelopmentInstanceSourceLifecycle::class => RemoteDevelopmentInstanceSourceLifecycle::class,
        DevelopmentInstanceBranchInspector::class => RemoteDevelopmentInstanceSourceLifecycle::class,
        RegistrationSourceManager::class => RemoteRegistrationSourceManager::class,
        DevelopmentInstanceSourceRemoval::class => RemoteDevelopmentInstanceSourceRemoval::class,
        ProductionInstanceSourceRemoval::class => RecordedProductionInstanceSourceRemoval::class,
        InstanceRemover::class => DestroyInstanceAction::class,
        InstanceRemovalProjector::class => NativeInstanceRemovalProjector::class,
        DevelopmentInstanceConfigurator::class => RemoteDevelopmentInstanceConfigurator::class,
        RouteApplicationUrlWriter::class => PlacementRouteApplicationUrlWriter::class,
        ProductionWebRootManager::class => RemoteProductionWebRootManager::class,
        ProductionRepositoryBinding::class => RemoteProductionRepositoryBinding::class,
        DevelopmentInstanceProvisioner::class => NativeDevelopmentInstanceProvisioner::class,
        DevelopmentRouteProjector::class => NativeDevelopmentRouteProjector::class,
        DevelopmentSourceAccess::class => NativeDevelopmentSourceAccess::class,
        ProductionInstanceProvisioner::class => NativeProductionInstanceProvisioner::class,
        ProductionDeployment::class => RemoteProductionDeployment::class,
        ProductionInstanceSourceLifecycle::class => RemoteProductionInstanceSourceLifecycle::class,
        ProductionReleaseLayout::class => RemoteProductionInstanceSourceLifecycle::class,
        ProductionPhpRuntimeManager::class => RemoteProductionPhpRuntimeManager::class,
        InstanceEnvironmentRuntimeRefresher::class => RemoteInstanceEnvironmentRuntimeRefresher::class,
        ProductionRouteProjector::class => NativeProductionRouteProjector::class,
        ProductionCloneRouteProjector::class => NativeProductionRouteProjector::class,
        InstanceEnvironmentSynchronizer::class => SynchronizeInstanceEnvironmentAction::class,
        InstanceRouteEnvironmentSynchronizer::class => SynchronizeInstanceEnvironmentAction::class,
        SqliteSnapshotTransfer::class => ProtectedSqliteSnapshotTransfer::class,
        InstanceLogReader::class => RemoteInstanceLogReader::class,
        InstanceQueueReader::class => RemoteInstanceQueueReader::class,
    ];

    public function register(): void
    {
        $this->app->tag([InstanceLifecycleGitSecret::class, InstanceLifecycleUrlSecret::class, EnvironmentSyncSecrets::class, InstanceStepListSecrets::class, InstanceDeploymentSecrets::class, InstanceVpEnvironmentSecrets::class], OperationSecretSource::class);
        $this->app->bind(SwampDependencyReader::class, static fn (): SwampDependencyReader => new WebSocketSwampDependencyReader(
            static fn (int $limit): WebSocketClient => new StreamWebSocketClient($limit, $limit),
            Config::string('swamp.address'), Config::string('swamp.read_token'), Config::string('swamp.ca_path'),
        ));
        $this->app->instance(AutomaticDeployPolicyWriter::class, AutomaticDeployPolicyWriter::Swamp);
        $this->app->scoped(
            InstanceEnvironmentOperationLock::class,
            static fn (): InstanceEnvironmentOperationLock => new NativeInstanceEnvironmentOperationLock(
                directory: rtrim(string: Config::string('orbit.home'), characters: '/')
                    .'/locks/app-instance-environment',
                deadline: app(CommandDeadline::class),
            ),
        );
        $this->app->singleton(
            DevelopmentInstanceSourceFinalizer::class,
            static fn (): DevelopmentInstanceSourceFinalizer => app(RemoteDevelopmentInstanceSourceRemoval::class),
        );
    }

    public function boot(): void
    {
        $this->commands([CheckInstanceDeploymentOperationsCommand::class]);
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            GatewaySchedule::command($schedule, 'orbit:instance-deployment-operations-check', uniqueFor: 120, overlapMinutes: 2)->everyMinute();
        });
        $this->app->booted(function (): void {
            foreach ($this->app->make(Router::class)->getRoutes()->getRoutes() as $route) {
                if ($route->getActionName() === OperationExecutorController::class.'@run') {
                    $route->middleware(SerializeInstanceEnvironment::class);
                }
            }
        });
    }
}
