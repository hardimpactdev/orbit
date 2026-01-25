<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Orbit Configuration Path
    |--------------------------------------------------------------------------
    |
    | This value determines the path where Orbit stores its configuration
    | and Docker compose files.
    |
    */

    'path' => env('ORBIT_PATH', getenv('HOME') ?: '/home/orbit'.'/.config/orbit'),

    /*
    |--------------------------------------------------------------------------
    | Supported PHP Versions
    |--------------------------------------------------------------------------
    |
    | The PHP versions that Orbit supports. These correspond to the
    | FrankenPHP Docker images that will be pulled during init.
    |
    */

    'php_versions' => ['8.3', '8.4', '8.5'],

];
