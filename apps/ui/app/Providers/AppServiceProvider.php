<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Orbit\Sdk\Laravel\GatewayConnector;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        $this->app->singleton(GatewayConnector::class, function (): GatewayConnector {
            $caPemPath = config('orbit.gateway.ca_pem_path');

            if (! is_string($caPemPath) && ! is_bool($caPemPath) && $caPemPath !== null) {
                $caPemPath = null;
            }

            if ($caPemPath === '' || (is_string($caPemPath) && ! is_file($caPemPath))) {
                $caPemPath = null;
            }

            return new GatewayConnector(
                clientName: 'ui',
                baseUrl: config()->string('orbit.gateway.url'),
                caPemPath: $caPemPath,
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());
        Model::preventAccessingMissingAttributes();
        Model::unguard();

        if ($this->app->environment('testing')) {
            Vite::useHotFile(storage_path('framework/testing/vite.hot'));
        }

        if ($this->app->isProduction()) {
            Vite::prefetch();
        }
    }
}
