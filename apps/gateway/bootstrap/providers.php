<?php

declare(strict_types=1);

use App\Providers\ApplicationServiceProvider;
use App\Providers\FleetServiceProvider;
use App\Providers\GatewayBoostServiceProvider;
use App\Providers\GatewayQueueServiceProvider;
use App\Providers\GatewayReleasesServiceProvider;
use App\Providers\ProxyCliServiceProvider;
use App\Providers\TasksServiceProvider;
use App\Providers\TaskVmServiceProvider;
use Orbit\Extensions\Conn\ConnServiceProvider;
use Orbit\Extensions\Documents\DocumentsServiceProvider;
use Orbit\Extensions\Vault\VaultServiceProvider;

return [
    ApplicationServiceProvider::class,
    FleetServiceProvider::class,
    GatewayBoostServiceProvider::class,
    GatewayQueueServiceProvider::class,
    GatewayReleasesServiceProvider::class,
    // Tasks, ProxyCli, Documents, Vault, and Conn are extensions: their providers implement App\Domain\Extensions\Extension.
    TasksServiceProvider::class,
    TaskVmServiceProvider::class,
    ProxyCliServiceProvider::class,
    DocumentsServiceProvider::class,
    VaultServiceProvider::class,
    ConnServiceProvider::class,
];
