<?php

namespace App\Providers;

use HardImpact\Orbit\Core\Models\Environment;
use HardImpact\Orbit\App\OrbitAppServiceProvider;
use Illuminate\Support\Facades\Artisan;
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
        // Ensure APP_KEY exists before anything tries to use encryption
        $this->ensureAppKeyExists();
    }

    /**
     * Generate APP_KEY if missing (fixes fresh install issues).
     */
    protected function ensureAppKeyExists(): void
    {
        if (empty(config('app.key'))) {
            try {
                Artisan::call('key:generate', ['--force' => true]);
            } catch (\Throwable $e) {
                // If artisan isn't available yet, generate key manually
                $key = 'base64:' . base64_encode(random_bytes(32));
                config(['app.key' => $key]);

                // Try to persist to .env if writable
                $envPath = base_path('.env');
                if (is_writable($envPath) || is_writable(dirname($envPath))) {
                    $envContent = file_exists($envPath) ? file_get_contents($envPath) : '';
                    if (str_contains($envContent, 'APP_KEY=')) {
                        $envContent = preg_replace('/^APP_KEY=.*/m', "APP_KEY={$key}", $envContent);
                    } else {
                        $envContent .= "\nAPP_KEY={$key}\n";
                    }
                    file_put_contents($envPath, $envContent);
                }
            }
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register orbit-app routes
        OrbitAppServiceProvider::routes();

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
