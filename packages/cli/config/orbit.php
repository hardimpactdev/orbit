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
    | host PHP-FPM versions that will be installed and managed.
    |
    */

    'php_versions' => ['8.3', '8.4', '8.5'],

];
