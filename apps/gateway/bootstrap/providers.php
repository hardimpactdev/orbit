<?php

declare(strict_types=1);

use App\Providers\ApplicationServiceProvider;
use App\Providers\FleetServiceProvider;
use App\Providers\GatewayBoostServiceProvider;
use App\Providers\GatewayQueueServiceProvider;
use App\Providers\GatewayReleasesServiceProvider;
use App\Providers\TasksServiceProvider;
use App\Providers\TaskVmServiceProvider;
use Orbit\Extensions\AiRouter\AiRouterServiceProvider;
use Orbit\Extensions\Conn\ConnServiceProvider;
use Orbit\Extensions\Documents\DocumentsServiceProvider;
use Orbit\Extensions\Vault\VaultServiceProvider;

$domains = array_map(
    static fn (string $file): string => 'App\\Providers\\Domains\\'.basename($file, '.php'),
    glob(__DIR__.'/../app/Providers/Domains/*ServiceProvider.php') ?: [],
);

return [
    ...$domains,
    ApplicationServiceProvider::class,
    FleetServiceProvider::class,
    GatewayBoostServiceProvider::class,
    GatewayQueueServiceProvider::class,
    GatewayReleasesServiceProvider::class,
    // Tasks, AiRouter, Documents, Vault, and Conn are extensions: their providers implement App\Domain\Extensions\Extension.
    TasksServiceProvider::class,
    TaskVmServiceProvider::class,
    AiRouterServiceProvider::class,
    DocumentsServiceProvider::class,
    VaultServiceProvider::class,
    ConnServiceProvider::class,
];
