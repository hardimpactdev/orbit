<?php

declare(strict_types=1);
use App\Domain\Routes\PrivateDnsTransport;
use App\Domain\Routes\RouteWorkloadTransport;

return ['route-workload' => RouteWorkloadTransport::class,
    'route-dns' => PrivateDnsTransport::class,
];
