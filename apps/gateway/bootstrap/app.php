<?php

use App\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$gatewayRoot = dirname(__DIR__);

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
    ->useDatabasePath($gatewayRoot.'/database')
    ->usePublicPath($gatewayRoot.'/public');

$app->singleton(ConsoleKernelContract::class, ConsoleKernel::class);

return $app;
