<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Orbit Mode
    |--------------------------------------------------------------------------
    |
    | Determines whether Orbit is running in web (single environment) or
    | desktop (multi-environment) mode.
    |
    */
    'mode' => env('ORBIT_MODE', 'web'),

    /*
    |--------------------------------------------------------------------------
    | Multi-Environment Management
    |--------------------------------------------------------------------------
    |
    | When true, enables multi-environment management UI and routing.
    | When false, uses implicit environment injection via middleware.
    |
    */
    'multi_environment' => env('MULTI_ENVIRONMENT_MANAGEMENT', false),

    /*
    |--------------------------------------------------------------------------
    | CLI Path
    |--------------------------------------------------------------------------
    |
    | Path to the Orbit CLI executable. Used for executing CLI commands.
    | For development: /home/user/projects/orbit-cli/orbit
    | For production: /home/user/.local/bin/orbit
    |
    */
    'cli_path' => env('ORBIT_CLI_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Database Path
    |--------------------------------------------------------------------------
    |
    | The path to the SQLite database file when running in CLI mode.
    |
    */
    'database' => ['path' => env('ORBIT_DATABASE_PATH')],

    /*
    |--------------------------------------------------------------------------
    | PostgreSQL Defaults
    |--------------------------------------------------------------------------
    |
    | Default PostgreSQL configuration used when provisioning sites.
    | These values are used when services.yaml doesn't exist or
    | doesn't specify PostgreSQL credentials.
    |
    | IMPORTANT: Change the default password in production!
    |
    */
    'postgres' => [
        'user' => env('ORBIT_POSTGRES_USER', 'orbit'),
        'password' => env('ORBIT_POSTGRES_PASSWORD', 'secret'),
        'port' => (int) env('ORBIT_POSTGRES_PORT', 5432),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default TLD
    |--------------------------------------------------------------------------
    |
    | The default top-level domain for local development sites.
    |
    */
    'tld' => env('ORBIT_TLD', 'ccc'),

    /*
    |--------------------------------------------------------------------------
    | Home Directory
    |--------------------------------------------------------------------------
    |
    | Fallback home directory when $_SERVER['HOME'] is not available.
    |
    */
    'home_directory' => env('ORBIT_HOME_DIRECTORY', '/home/orbit'),

    /*
    |--------------------------------------------------------------------------
    | Provisioning Timeouts
    |--------------------------------------------------------------------------
    |
    | Timeout values (in seconds) for various provisioning operations.
    |
    */
    'timeouts' => [
        'provisioning' => (int) env('ORBIT_PROVISIONING_TIMEOUT', 600),
        'composer_install' => (int) env('ORBIT_COMPOSER_TIMEOUT', 600),
        'npm_install' => (int) env('ORBIT_NPM_TIMEOUT', 600),
    ],
];
