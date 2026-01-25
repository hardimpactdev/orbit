<?php

namespace App\Providers;

use HardImpact\Orbit\Models\Environment;
use HardImpact\Orbit\OrbitServiceProvider;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register orbit-core routes
        OrbitServiceProvider::routes();

        // Override inertia root view to use local blade template
        // (orbit-core sets it to orbit::app which expects pre-built assets)
        config(['inertia.root_view' => 'app']);

        Inertia::share([
            'multi_environment' => fn () => config('orbit.multi_environment'),
            'currentEnvironment' => fn () => config('orbit.multi_environment')
                ? null
                : Environment::where('is_local', true)->first(),
        ]);
    }
}
