<?php

declare(strict_types=1);

namespace App\Providers;

use App\Librarian\CliSurface;
use App\Librarian\CommandCatalogRepositoryPaths;
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
        $this->app->singleton(
            CommandCatalogRepositoryPaths::class,
            fn (): CommandCatalogRepositoryPaths => new CommandCatalogRepositoryPaths(
                dirname($this->app->basePath(), levels: 2),
            ),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
