<?php

declare(strict_types=1);

return [
    'is_gateway' => env('ORBIT_IS_GATEWAY', false),
    'e2e_trust_wireguard_header' => env('ORBIT_E2E_TRUST_WIREGUARD_HEADER', false),
    'operation_token_secret' => env('ORBIT_OPERATION_TOKEN_SECRET'),
    'operation_token_ttl_seconds' => env('ORBIT_OPERATION_TOKEN_TTL_SECONDS', 120),

    'paths' => [
        'config_root' => env('ORBIT_CONFIG_ROOT'),
    ],

    'cloudflare' => [
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
        'api_email' => env('CLOUDFLARE_API_EMAIL'),
    ],
];
