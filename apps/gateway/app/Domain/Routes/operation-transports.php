<?php

declare(strict_types=1);
use App\Domain\Routes\CaddyTransport;
use App\Domain\Routes\PrivateDnsTransport;

return [
    'route-caddy' => CaddyTransport::class,
    'route-dns' => PrivateDnsTransport::class,
];
