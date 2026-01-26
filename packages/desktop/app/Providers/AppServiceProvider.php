<?php

namespace App\Providers;

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\Ui\UiServiceProvider;
use Illuminate\Support\Facades\Route;
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
        // Register orbit-app routes
        UiServiceProvider::routes();
        
        // Register desktop-specific routes
        $this->loadDesktopRoutes();

        // Override inertia root view to use local blade template
        // (orbit-app sets it to orbit::app which expects pre-built assets)
        config(['inertia.root_view' => 'app']);

        Inertia::share([
            'multi_environment' => fn () => config('orbit.multi_environment'),
            'currentEnvironment' => fn () => config('orbit.multi_environment')
                ? null
                : Environment::where('is_local', true)->first(),
            'cli' => fn () => [
                'installed' => app(\App\Services\CliInstallService::class)->isInstalled(),
            ],
        ]);
    }
    
    protected function loadDesktopRoutes(): void
    {
        Route::middleware('web')
            ->group(base_path('routes/web.php'));
    }
}
