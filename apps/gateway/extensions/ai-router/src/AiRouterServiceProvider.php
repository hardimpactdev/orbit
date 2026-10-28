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
use App\Models\DatabaseConnection;
use App\Providers\ExtensionServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\AiRouter\Actions\ReconvergeAiRouterConnectionAction;
use Orbit\Extensions\AiRouter\Console\ConvergeAiRouterCommand;
use Orbit\Extensions\AiRouter\Domain\AiRouterAccountControlClient;
use Orbit\Extensions\AiRouter\Domain\AiRouterCache;
use Orbit\Extensions\AiRouter\Domain\AiRouterConnectionOwnership;
use Orbit\Extensions\AiRouter\Domain\AiRouterProcessOwnership;
use Orbit\Extensions\AiRouter\Domain\AiRouterSnapshotStore;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterCaddySiteSource;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterFootprintArtifact;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterModelKeyIssuer;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterPrivateHostnames;
use Orbit\Extensions\AiRouter\Infrastructure\ArrayAiRouterCache;
use Orbit\Extensions\AiRouter\Infrastructure\HttpAiRouterAccountControlClient;
use Orbit\Extensions\AiRouter\Infrastructure\ValkeyAiRouterCache;

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

            $slug = $app->make(AiRouterState::class)->cacheConnection();
            $connection = is_string($slug)
                ? DatabaseConnection::query()->where('slug', $slug)->first()
                : null;

            return $connection instanceof DatabaseConnection
                ? new ValkeyAiRouterCache($connection)
                : $app->make(ArrayAiRouterCache::class);
        });
        $this->app->singleton(AiRouterSnapshotStore::class);
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
