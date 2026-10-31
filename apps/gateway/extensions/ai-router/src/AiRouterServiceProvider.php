<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter;

use App\Console\Scheduling\GatewaySchedule;
use App\Domain\DatabaseConnections\DatabaseConnectionRemovalGuard;
use App\Domain\DatabaseConnections\DatabaseConnectionUpdateHook;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\Processes\ProcessRemovalGuard;
use App\Domain\Routes\ContributedPrivateHostnames;
use App\Domain\TaskVms\ModelKeyIssuer;
use App\Infrastructure\Caddy\Build\ContributedCaddySiteSource;
use App\Providers\ExtensionServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\AiRouter\Actions\ReconvergeAiRouterConnectionAction;
use Orbit\Extensions\AiRouter\Console\ConvergeAiRouterCommand;
use Orbit\Extensions\AiRouter\Domain\AiRouterAccountControlClient;
use Orbit\Extensions\AiRouter\Domain\AiRouterCache;
use Orbit\Extensions\AiRouter\Domain\AiRouterConnectionOwnership;
use Orbit\Extensions\AiRouter\Domain\AiRouterProcessOwnership;
use Orbit\Extensions\AiRouter\Domain\AiRouterPublicationManager;
use Orbit\Extensions\AiRouter\Domain\AiRouterRuntimeLifecycle;
use Orbit\Extensions\AiRouter\Domain\AiRouterSnapshotStore;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterCaddySiteSource;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterFootprintArtifact;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterModelKeyIssuer;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterPrivateHostnames;
use Orbit\Extensions\AiRouter\Infrastructure\ArrayAiRouterCache;
use Orbit\Extensions\AiRouter\Infrastructure\ConfiguredAiRouterCache;
use Orbit\Extensions\AiRouter\Infrastructure\HttpAiRouterAccountControlClient;
use Orbit\Extensions\AiRouter\Infrastructure\NativeAiRouterPublicationManager;
use Orbit\Extensions\AiRouter\Infrastructure\NativeAiRouterRuntimeLifecycle;
use Orbit\Extensions\AiRouter\Infrastructure\RecordingAiRouterPublicationManager;
use Orbit\Extensions\AiRouter\Infrastructure\RecordingAiRouterRuntimeLifecycle;

/** The ai-router extension. See [Extensions](/reference/extensions#the-extensions). */
final class AiRouterServiceProvider extends ExtensionServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ModelKeyIssuer::class => AiRouterModelKeyIssuer::class,
        AiRouterAccountControlClient::class => HttpAiRouterAccountControlClient::class,
    ];

    public function name(): string
    {
        return 'ai-router';
    }

    public function description(): string
    {
        return 'Collects CLIProxyAPI provider quota and shows it on the AI router page.';
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(ArrayAiRouterCache::class);
        $this->app->singleton(AiRouterCache::class, function ($app): AiRouterCache {
            if ($app->environment('testing')) {
                return $app->make(ArrayAiRouterCache::class);
            }

            return new ConfiguredAiRouterCache($app->make(AiRouterState::class), $app->make(ArrayAiRouterCache::class));
        });
        $this->app->singleton(AiRouterSnapshotStore::class);
        if ($this->app->environment('testing')) {
            $this->app->singleton(RecordingAiRouterRuntimeLifecycle::class);
            $this->app->singleton(RecordingAiRouterPublicationManager::class);
            $this->app->singleton(AiRouterRuntimeLifecycle::class, static fn ($app): AiRouterRuntimeLifecycle => $app->make(RecordingAiRouterRuntimeLifecycle::class));
            $this->app->singleton(AiRouterPublicationManager::class, static fn ($app): AiRouterPublicationManager => $app->make(RecordingAiRouterPublicationManager::class));
        } else {
            $this->app->bind(AiRouterRuntimeLifecycle::class, NativeAiRouterRuntimeLifecycle::class);
            $this->app->bind(AiRouterPublicationManager::class, NativeAiRouterPublicationManager::class);
        }
        $this->contribute(DatabaseConnectionUpdateHook::class, ReconvergeAiRouterConnectionAction::class);
        $this->contribute(DatabaseConnectionRemovalGuard::class, AiRouterConnectionOwnership::class);
        $this->contribute(ProcessRemovalGuard::class, AiRouterProcessOwnership::class);
        $this->contribute(ContributedCaddySiteSource::class, AiRouterCaddySiteSource::class);
        $this->contribute(NodeFootprintArtifact::class, AiRouterFootprintArtifact::class);
        $this->contribute(ContributedPrivateHostnames::class, AiRouterPrivateHostnames::class);
    }

    public function boot(): void
    {
        $this->commands([ConvergeAiRouterCommand::class]);
        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            GatewaySchedule::command($schedule, 'orbit:ai-router-converge', uniqueFor: 600, overlapMinutes: 10)->everyMinute()
                ->when(static fn (): bool => app(ExtensionStore::class)->enabled('ai-router') || DB::table('ai_router_cutovers')->exists());
        });
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
    }
}
