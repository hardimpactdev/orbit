<?php

declare(strict_types=1);

return [
    'is_gateway' => env('ORBIT_IS_GATEWAY', false),

    'paths' => [
        'config_root' => env('ORBIT_CONFIG_ROOT'),
    ],

    'cloudflare' => [
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
        'api_email' => env('CLOUDFLARE_API_EMAIL'),
    ],
];
