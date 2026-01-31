<?php

namespace HardImpact\Orbit\Core;

use HardImpact\Orbit\Core\Console\Commands\OrbitInit;
use HardImpact\Orbit\Core\Services\CliUpdateService;
use HardImpact\Orbit\Core\Services\DnsResolverService;
use HardImpact\Orbit\Core\Services\DoctorService;
use HardImpact\Orbit\Core\Services\EnvironmentManager;
use HardImpact\Orbit\Core\Services\HorizonService;
use HardImpact\Orbit\Core\Services\OrbitCli\ConfigurationService;
use HardImpact\Orbit\Core\Services\OrbitCli\PackageService;
use HardImpact\Orbit\Core\Services\OrbitCli\ProjectCliService;
use HardImpact\Orbit\Core\Services\OrbitCli\ServiceControlService;
use HardImpact\Orbit\Core\Services\OrbitCli\Shared\CommandService;
use HardImpact\Orbit\Core\Services\OrbitCli\StatusService;
use HardImpact\Orbit\Core\Services\OrbitCli\WorkspaceService;
use HardImpact\Orbit\Core\Services\OrbitCli\WorktreeService;
use HardImpact\Orbit\Core\Services\SshService;
use Illuminate\Support\ServiceProvider;

class OrbitCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/orbit.php', 'orbit');

        // Register stateless services as singletons to avoid repeated instantiation
        // This improves performance, especially in long-running processes like Horizon
        $this->app->singleton(EnvironmentManager::class);
        $this->app->singleton(SshService::class);
        $this->app->singleton(DoctorService::class);
        $this->app->singleton(HorizonService::class);
        $this->app->singleton(DnsResolverService::class);
        $this->app->singleton(CliUpdateService::class);

        // CLI wrapper services (stateless, reusable)
        $this->app->singleton(CommandService::class);
        $this->app->singleton(StatusService::class);
        $this->app->singleton(ConfigurationService::class);
        $this->app->singleton(ProjectCliService::class);
        $this->app->singleton(WorkspaceService::class);
        $this->app->singleton(WorktreeService::class);
        $this->app->singleton(PackageService::class);
        $this->app->singleton(ServiceControlService::class);
    }

    public function boot(): void
    {
        $this->registerCommands();
        $this->registerMigrations();
        $this->registerPublishing();
        $this->registerRouteBindings();
    }

    protected function registerRouteBindings(): void
    {
        // Explicit route model binding for Environment
        // Required because the model is in HardImpact\Orbit\Core\Models namespace
        // not App\Models, so Laravel can't auto-discover it
        \Illuminate\Support\Facades\Route::model('environment', \HardImpact\Orbit\Core\Models\Environment::class);
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                OrbitInit::class,
            ]);
        }
    }

    protected function registerMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function registerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/orbit.php' => config_path('orbit.php'),
            ], 'orbit-config');
        }
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            OrbitInit::class,
            EnvironmentManager::class,
            SshService::class,
            DoctorService::class,
            HorizonService::class,
            DnsResolverService::class,
            CliUpdateService::class,
            CommandService::class,
            StatusService::class,
            ConfigurationService::class,
            ProjectCliService::class,
            WorkspaceService::class,
            WorktreeService::class,
            PackageService::class,
            ServiceControlService::class,
        ];
    }
}
