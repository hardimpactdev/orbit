<?php

declare(strict_types=1);

use App\Providers\GatewayApiServiceProvider;
use App\Providers\OperationTokenGuardServiceProvider;

return [
    'name' => 'Orbit',
    'version' => env('ORBIT_VERSION', (static function (): string {
        $versionFile = dirname(__DIR__, 3).'/VERSION';

        if (is_file($versionFile)) {
            $version = trim((string) file_get_contents($versionFile));

            if ($version !== '') {
                return $version;
            }
        }

        return '0.0.0';
    })()),
    'env' => env('APP_ENV', 'development'),
    'providers' => [
        GatewayApiServiceProvider::class,
        OperationTokenGuardServiceProvider::class,
    ],
    'aliases' => [],
];
