<?php

namespace App\Providers;

use HardImpact\Orbit\Ui\UiServiceProvider;
use Illuminate\Support\ServiceProvider;

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
        // Register orbit-ui routes
        UiServiceProvider::routes();
    }
}
