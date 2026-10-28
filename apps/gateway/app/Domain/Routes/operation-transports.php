<?php

declare(strict_types=1);
use App\Domain\Routes\CaddyTransport;
use App\Domain\Routes\PrivateDnsTransport;
use App\Domain\Routes\RouteWorkloadTransport;

return ['route-workload' => RouteWorkloadTransport::class,
    'route-caddy' => CaddyTransport::class,
    'route-dns' => PrivateDnsTransport::class,
];
