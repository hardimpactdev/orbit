<?php

namespace HardImpact\Orbit\Core;

use HardImpact\Orbit\Core\Console\Commands\OrbitInit;
use HardImpact\Orbit\Core\Services\EnvironmentManager;
use Illuminate\Support\ServiceProvider;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/orbit.php', 'orbit');

        $this->app->singleton(EnvironmentManager::class);
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
        ];
    }
}
