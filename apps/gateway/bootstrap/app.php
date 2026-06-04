<?php

use App\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$gatewayRoot = dirname(__DIR__);
$orbitPaths = require __DIR__.'/orbit_paths.php';

$app = Application::configure(basePath: $gatewayRoot)
    ->withProviders(require __DIR__.'/providers.php', withBootstrapProviders: false)
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();

$app
    ->useEnvironmentPath(dirname($orbitPaths['env_path']))
    ->loadEnvironmentFrom(basename($orbitPaths['env_path']))
    ->useDatabasePath($orbitPaths['database_path'])
    ->useStoragePath($orbitPaths['storage_path'])
    ->usePublicPath($gatewayRoot.'/public');

if ($bootstrapCachePath = getenv('ORBIT_BOOTSTRAP_CACHE_PATH')) {
    $_ENV['APP_SERVICES_CACHE'] = $bootstrapCachePath.'/services.php';
    $_ENV['APP_PACKAGES_CACHE'] = $bootstrapCachePath.'/packages.php';
    $_ENV['APP_CONFIG_CACHE'] = $bootstrapCachePath.'/config.php';
    $_ENV['APP_ROUTES_CACHE'] = $bootstrapCachePath.'/routes-v7.php';
    $_ENV['APP_EVENTS_CACHE'] = $bootstrapCachePath.'/events.php';
}

$app->singleton(ConsoleKernelContract::class, ConsoleKernel::class);

return $app;
