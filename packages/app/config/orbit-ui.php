<?php

declare(strict_types=1);

// config for HardImpact\Orbit\Ui
return [
    /*
    |--------------------------------------------------------------------------
    | Default PHP Versions
    |--------------------------------------------------------------------------
    |
    | Default PHP versions to use when provisioning environments.
    | These are used as fallback when environment-specific versions
    | cannot be retrieved.
    |
    */
    'php' => [
        'versions' => ['8.3', '8.4', '8.5'],
        'default' => '8.4',
        'recommended' => '8.5',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Ports
    |--------------------------------------------------------------------------
    |
    | Default ports for various services.
    |
    */
    'ports' => [
        'ssh' => 22,
        'reverb' => 443,
    ],

    /*
    |--------------------------------------------------------------------------
    | Provisioning Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for environment provisioning process.
    |
    */
    'provisioning' => [
        'mac_setup_steps' => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | Path Configuration
    |--------------------------------------------------------------------------
    |
    | Default path prefixes and patterns.
    |
    */
    'paths' => [
        'home_prefix' => '/home/',
    ],
];
