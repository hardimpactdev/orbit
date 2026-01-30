<?php
declare(strict_types=1);

namespace HardImpact\Orbit\Ui;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class OrbitAppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // UI package doesn't need to register core services
        // Those are handled by orbit-core
    }

    public function boot(): void
    {
        $this->configureVite();
        $this->registerViews();
        $this->registerMiddleware();
        $this->registerMcp();
        $this->registerPublishing();
        $this->registerRouteBindings();
    }

    protected function registerRouteBindings(): void
    {
        // Route model binding for Environment must be registered in boot()
        // because Route::model() needs the router to be available
        Route::model('environment', \HardImpact\Orbit\Core\Models\Environment::class);
    }

    protected function registerMiddleware(): void
    {
        // Skip middleware registration when router isn't available (e.g., Laravel Zero CLI)
        if (! $this->app->bound('router')) {
            return;
        }

        // Skip if HTTP Kernel isn't available (pure CLI context)
        $kernelContract = 'Illuminate\Contracts\Http\Kernel';
        if (! $this->app->bound($kernelContract)) {
            return;
        }

        /** @var \Illuminate\Foundation\Http\Kernel $kernel */
        $kernel = $this->app->make($kernelContract);

        // Register Inertia middleware (only when Inertia is available)
        if (class_exists(\Inertia\Middleware::class)) {
            $kernel->appendMiddlewareToGroup('web', \HardImpact\Orbit\Ui\Http\Middleware\HandleInertiaRequests::class);
        }

        // Register alias for route usage
        if (class_exists(\HardImpact\Orbit\Ui\Http\Middleware\ImplicitEnvironment::class)) {
            $this->app['router']->aliasMiddleware('implicit.environment', \HardImpact\Orbit\Ui\Http\Middleware\ImplicitEnvironment::class);
        }
    }

    protected function configureVite(): void
    {
        // Vite facade may not exist in CLI-only contexts
        if (! class_exists(\Illuminate\Support\Facades\Vite::class)) {
            return;
        }

        Vite::useHotFile(__DIR__.'/../public/hot');
        Vite::useBuildDirectory('vendor/orbit/build');
    }

    protected function registerViews(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'orbit');
        config(['inertia.root_view' => 'orbit::app']);
    }

    /**
     * Register the package's routes.
     * Called by consuming apps in their RouteServiceProvider or bootstrap.
     */
    public static function routes(): void
    {
        Route::middleware('web')
            ->group(__DIR__.'/../routes/web.php');

        // API routes must use withoutMiddleware to prevent web middleware
        // from being inherited when called from within web routes file
        Route::prefix('api')
            ->withoutMiddleware(['web', \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
            ->middleware('api')
            ->group(__DIR__.'/../routes/api.php');
    }

    protected function registerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../public/build' => public_path('vendor/orbit/build'),
            ], 'orbit-assets');
        }
    }

    /**
     * Register MCP routes for AI tool integration.
     */
    protected function registerMcp(): void
    {
        // Only load MCP routes when laravel/mcp is available
        if (class_exists(\Laravel\Mcp\Facades\Mcp::class)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/mcp.php');
        }
    }
}
