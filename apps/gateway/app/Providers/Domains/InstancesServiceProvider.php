<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Actions\Instances\SynchronizeInstanceEnvironmentAction;
use App\Domain\Instances\Dependencies\SwampDependencyReader;
use App\Domain\Instances\Deployment\AutomaticDeployPolicyWriter;
use App\Domain\Instances\DevelopmentInstanceConfigurator;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Instances\DevelopmentSourceAccess;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentSynchronizer;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Instances\Environment\InstanceRouteEnvironmentSynchronizer;
use App\Domain\Instances\Environment\InstanceTestEnvironmentWriter;
use App\Domain\Instances\InstanceDestroyTeardownSecret;
use App\Domain\Instances\InstanceNodeServiceProcessRemovalGuard;
use App\Domain\Instances\InstanceOperationAlerts;
use App\Domain\Instances\Logs\InstanceLogReader;
use App\Domain\Instances\NativeInstanceProvisioningSetup;
use App\Domain\Instances\ProductionPhpRuntimeManager;
use App\Domain\Instances\ProductionRepositoryBinding;
use App\Domain\Instances\Queue\InstanceQueueReader;
use App\Domain\Instances\RouteApplicationUrlWriter;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Processes\ProcessRemovalGuard;
use App\Infrastructure\AgentView\StreamWebSocketClient;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Dependencies\WebSocketSwampDependencyReader;
use App\Infrastructure\Instances\NativeDevelopmentRouteProjector;
use App\Infrastructure\Instances\NativeDevelopmentSourceAccess;
use App\Infrastructure\Instances\NativeInstanceEnvironmentOperationLock;
use App\Infrastructure\Instances\PlacementRouteApplicationUrlWriter;
use App\Infrastructure\Instances\RemoteDevelopmentInstanceConfigurator;
use App\Infrastructure\Instances\RemoteInstanceEnvironmentAccess;
use App\Infrastructure\Instances\RemoteInstanceLogReader;
use App\Infrastructure\Instances\RemoteProductionPhpRuntimeManager;
use App\Infrastructure\Instances\RemoteProductionRepositoryBinding;
use App\Infrastructure\Instances\StoreInstanceQueueReader;
use App\Models\Operation;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class InstancesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Operation::updated(static fn (Operation $operation) => app(InstanceOperationAlerts::class)->changed($operation));
    }

    /** @var array<class-string, class-string> */
    public array $bindings = [
        InstanceEnvironmentWriter::class => RemoteInstanceEnvironmentAccess::class,
        InstanceTestEnvironmentWriter::class => RemoteInstanceEnvironmentAccess::class,
        InstanceOperationPreflight::class => RemoteInstanceEnvironmentAccess::class,
        DevelopmentInstanceConfigurator::class => RemoteDevelopmentInstanceConfigurator::class,
        RouteApplicationUrlWriter::class => PlacementRouteApplicationUrlWriter::class,
        ProductionRepositoryBinding::class => RemoteProductionRepositoryBinding::class,
        DevelopmentRouteProjector::class => NativeDevelopmentRouteProjector::class,
        DevelopmentSourceAccess::class => NativeDevelopmentSourceAccess::class,
        ProductionPhpRuntimeManager::class => RemoteProductionPhpRuntimeManager::class,
        InstanceEnvironmentSynchronizer::class => SynchronizeInstanceEnvironmentAction::class,
        InstanceRouteEnvironmentSynchronizer::class => SynchronizeInstanceEnvironmentAction::class,
        InstanceLogReader::class => RemoteInstanceLogReader::class,
        InstanceQueueReader::class => StoreInstanceQueueReader::class,
    ];

    public function register(): void
    {
        $this->app->tag([InstanceNodeServiceProcessRemovalGuard::class], ProcessRemovalGuard::class);
        $this->app->tag([NativeInstanceProvisioningSetup::class, InstanceDestroyTeardownSecret::class], OperationSecretSource::class);
        $this->app->bind(SwampDependencyReader::class, static fn (): SwampDependencyReader => new WebSocketSwampDependencyReader(
            static fn (int $limit): WebSocketClient => new StreamWebSocketClient($limit, $limit),
            Config::string('swamp.address'), Config::string('swamp.read_token'), Config::string('swamp.ca_path'),
        ));
        $this->app->instance(AutomaticDeployPolicyWriter::class, AutomaticDeployPolicyWriter::Swamp);
        $this->app->scoped(InstanceEnvironmentOperationLock::class, NativeInstanceEnvironmentOperationLock::class);
    }
}
