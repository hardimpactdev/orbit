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
use Orbit\Extensions\Documents\DocumentsServiceProvider;

return [
    ApplicationServiceProvider::class,
    FleetServiceProvider::class,
    GatewayBoostServiceProvider::class,
    GatewayQueueServiceProvider::class,
    GatewayReleasesServiceProvider::class,
    // Tasks, ProxyCli, and Documents are extensions: their providers implement App\Domain\Extensions\Extension.
    TasksServiceProvider::class,
    TaskVmServiceProvider::class,
    ProxyCliServiceProvider::class,
    DocumentsServiceProvider::class,
];
