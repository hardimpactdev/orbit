<?php

declare(strict_types=1);

namespace App\Providers;

use App\Librarian\CliSurface;
use App\Librarian\LiveCliSurface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        $this->app->singleton(CliSurface::class, LiveCliSurface::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
