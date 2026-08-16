<?php

declare(strict_types=1);

namespace App\Providers;

use App\Librarian\CliSurface;
use App\Librarian\CommandCatalogRepositoryPaths;
use App\Librarian\LiveCliSurface;
use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Linter;
use Illuminate\Container\Container;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        $this->app->singleton(OrbitCommandDocs::class);
        $this->app->beforeResolving(
            Linter::class,
            static function (string $_abstract, array $_parameters, Container $container): void {
                $container->forgetInstance(OrbitCommandDocs::class);
            },
        );
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
