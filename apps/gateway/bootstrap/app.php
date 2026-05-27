<?php

use App\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Console\Kernel as ConsoleKernelContract;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$repoRoot = dirname(__DIR__, 3);
$gatewayRoot = dirname(__DIR__);

$app = Application::configure(basePath: $repoRoot)
    ->withProviders(require $gatewayRoot.'/bootstrap/providers.php', withBootstrapProviders: false)
    ->withRouting(
        web: $gatewayRoot.'/routes/web.php',
        api: $gatewayRoot.'/routes/api.php',
        commands: $gatewayRoot.'/routes/console.php',
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
    ->useAppPath($gatewayRoot.'/app')
    ->useBootstrapPath($gatewayRoot.'/bootstrap')
    ->useConfigPath($gatewayRoot.'/config')
    ->useDatabasePath($gatewayRoot.'/database')
    ->useEnvironmentPath($repoRoot)
    ->usePublicPath($repoRoot.'/public')
    ->useStoragePath($repoRoot.'/storage');

$app->singleton(ConsoleKernelContract::class, ConsoleKernel::class);

return $app;
