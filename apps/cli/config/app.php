<?php

declare(strict_types=1);

use App\Providers\GatewayApiServiceProvider;
use App\Providers\OperationTokenGuardServiceProvider;

return [
    'name' => 'Orbit',
    'version' => '0.1.3',
    'env' => env('APP_ENV', 'development'),
    'providers' => [
        GatewayApiServiceProvider::class,
        OperationTokenGuardServiceProvider::class,
    ],
    'aliases' => [],
];
