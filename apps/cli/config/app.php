<?php

declare(strict_types=1);
use App\Providers\GatewayApiServiceProvider;

return [
    'name' => 'Orbit',
    'version' => '0.1.0',
    'env' => env('APP_ENV', 'development'),
    'providers' => [
        GatewayApiServiceProvider::class,
    ],
    'aliases' => [],
];
