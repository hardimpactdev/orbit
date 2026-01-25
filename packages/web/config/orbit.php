<?php

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
    | Database Path
    |--------------------------------------------------------------------------
    |
    | The path to the SQLite database file when running in CLI mode.
    |
    */
    'database' => ['path' => env('ORBIT_DATABASE_PATH')],
];
