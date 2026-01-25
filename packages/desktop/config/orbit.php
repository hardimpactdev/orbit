<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Orbit Mode
    |--------------------------------------------------------------------------
    |
    | Desktop mode enables multi-environment management with prefixed routes.
    | Web mode uses a single implicit environment with flat routes.
    |
    */
    'mode' => env('ORBIT_MODE', 'desktop'),

    /*
    |--------------------------------------------------------------------------
    | Multi-Environment Management
    |--------------------------------------------------------------------------
    |
    | When true, enables multi-environment management UI and routing.
    | This is the default for orbit-desktop (NativePHP app).
    |
    */
    'multi_environment' => env('MULTI_ENVIRONMENT_MANAGEMENT', true),

    'api_url' => env('ORBIT_API_URL'),
];
