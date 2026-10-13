<?php

declare(strict_types=1);

$configuredHome = env('ORBIT_HOME');
$userHome = getenv('HOME');
$orbitHome = base_path('.orbit');

if (is_string($userHome) && $userHome !== '') {
    $orbitHome = $userHome.'/.orbit';
}

if (is_string($configuredHome) && $configuredHome !== '') {
    $orbitHome = $configuredHome;
}

$configuredDatabase = env('DB_DATABASE');
$database = is_string($configuredDatabase) && $configuredDatabase !== ''
    ? $configuredDatabase
    : rtrim(string: $orbitHome, characters: '/').'/gateway.sqlite';

return [
    'default' => 'sqlite',

    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => env(key: 'DB_FOREIGN_KEYS', default: true),
            'busy_timeout' => 5_000,
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
            'transaction_mode' => 'IMMEDIATE',
        ],
    ],

    // The Gateway queue's Redis server. GatewayQueueRedis fills in host, port, and password from the registered
    // Database connection named by ORBIT_GATEWAY_QUEUE_CONNECTION when a process first uses Redis.
    'redis' => [
        'client' => 'predis',
        'gateway' => [
            // Unresolvable until GatewayQueueRedis fills it in, so a missing connection fails with this name.
            'host' => 'orbit-gateway-queue-unconfigured.invalid',
            'port' => 6379,
            'password' => null,
            'database' => (int) env('ORBIT_GATEWAY_QUEUE_REDIS_DATABASE', 1),
            'options' => ['prefix' => 'orbit-gateway:'],
        ],
    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],
];
