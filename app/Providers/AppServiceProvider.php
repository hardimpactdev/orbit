<?php

namespace App\Providers;

use App\Models\Environment;
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
        Inertia::share([
            'multi_environment' => fn () => config('orbit.multi_environment'),
            'currentEnvironment' => fn () => config('orbit.multi_environment')
                ? null
                : Environment::where('is_local', true)->first(),
        ]);
    }
}
