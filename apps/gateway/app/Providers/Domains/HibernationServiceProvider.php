<?php

declare(strict_types=1);

namespace App\Providers\Domains;

use App\Actions\Hibernation\SweepIdleAppDevRuntimesAction;
use App\Domain\AgentView\AgentProcessView;
use App\Domain\Hibernation\DevelopmentHibernationPolicy;
use App\Domain\Hibernation\HibernationMarkerStore;
use App\Domain\Hibernation\HibernationWakeFailureStore;
use App\Domain\Hibernation\InstanceCheckoutInspector;
use App\Domain\Hibernation\InstanceRuntimeReadiness;
use App\Domain\Hibernation\RuntimeHibernatorConverger;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Gateway\GatewayServiceCommands;
use App\Infrastructure\Hibernation\CacheHibernationWakeFailureStore;
use App\Infrastructure\Hibernation\NativeRuntimeHibernatorConverger;
use App\Infrastructure\Hibernation\RemoteHibernationMarkerStore;
use App\Infrastructure\Hibernation\RemoteInstanceCheckoutInspector;
use App\Infrastructure\Hibernation\RemoteInstanceRuntimeReadiness;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

final class HibernationServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        HibernationMarkerStore::class => RemoteHibernationMarkerStore::class,
        InstanceCheckoutInspector::class => RemoteInstanceCheckoutInspector::class,
        HibernationWakeFailureStore::class => CacheHibernationWakeFailureStore::class,
    ];

    public function register(): void
    {

        $this->app->bind(
            SweepIdleAppDevRuntimesAction::class,
            static fn ($app): SweepIdleAppDevRuntimesAction => new SweepIdleAppDevRuntimesAction(
                policy: $app->make(DevelopmentHibernationPolicy::class),
                admissions: $app->make(ProcessAdmissionLock::class),
                runtime: $app->make(ProcessRuntimeManager::class),
                markers: $app->make(HibernationMarkerStore::class),
                checkouts: $app->make(InstanceCheckoutInspector::class),
                idleSeconds: Config::integer('orbit.hibernation.idle_seconds'),
                dependencyIdleSeconds: Config::integer('orbit.hibernation.dependency_idle_seconds'),
                agents: $app->make(AgentProcessView::class),
            ),
        );
        $this->app->bind(
            RuntimeHibernatorConverger::class,
            static fn ($app): NativeRuntimeHibernatorConverger => new NativeRuntimeHibernatorConverger(
                commands: $app->make(GatewayServiceCommands::class),
                sweepSeconds: Config::integer('orbit.hibernation.sweep_seconds'),
            ),
        );
        $this->app->bind(
            InstanceRuntimeReadiness::class,
            static fn ($app): RemoteInstanceRuntimeReadiness => new RemoteInstanceRuntimeReadiness(
                runtime: $app->make(ProcessRuntimeManager::class),
                timeoutSeconds: Config::integer('orbit.hibernation.wake_timeout_seconds'),
                agents: $app->make(AgentProcessView::class),
            ),
        );
        $this->app->bind(
            RemoteInstanceCheckoutInspector::class,
            static fn ($app): RemoteInstanceCheckoutInspector => new RemoteInstanceCheckoutInspector(
                agents: $app->make(AgentCommandRunner::class),
                accounts: $app->make(ManagedUserAccountResolver::class),
                restoreTimeoutSeconds: Config::integer('orbit.hibernation.cold_wake_timeout_seconds'),
            ),
        );
    }
}
