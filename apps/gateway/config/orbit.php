<?php

declare(strict_types=1);

return [
    'is_gateway' => env('ORBIT_IS_GATEWAY', false),
    'e2e_topology_provider' => env('ORBIT_E2E_TOPOLOGY_PROVIDER'),
    'e2e_trust_wireguard_header' => env('ORBIT_E2E_TRUST_WIREGUARD_HEADER', false),
    'trust_wireguard_proxy_header' => env('ORBIT_TRUST_WIREGUARD_PROXY_HEADER', false),
    'forward_install_image_archives' => env('ORBIT_FORWARD_INSTALL_IMAGE_ARCHIVES', false),
    'operation_token_secret' => env('ORBIT_OPERATION_TOKEN_SECRET'),
    'operation_token_ttl_seconds' => env('ORBIT_OPERATION_TOKEN_TTL_SECONDS', 120),

    'paths' => [
        'config_root' => env('ORBIT_CONFIG_ROOT'),
    ],

    'cloudflare' => [
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
        'api_email' => env('CLOUDFLARE_API_EMAIL'),
    ],

    'operation_runs' => [
        'retention_days' => env('ORBIT_OPERATION_RUNS_RETENTION_DAYS', 90),
    ],
];
