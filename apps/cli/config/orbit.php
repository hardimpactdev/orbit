<?php

declare(strict_types=1);

/**
 * Env-only by design (D13). The env-then-JSON precedence chain lives inside
 * `GatewayApiServiceProvider`'s binding closure for `GatewayApiClient`. This file is the
 * env layer; `OrbitConfigStore` is the JSON layer. The provider closure overlays env on top
 * of JSON at construction time.
 *
 * `config:cache` interaction: the cached config does not reflect later edits to
 * `~/.config/orbit/config.json` until the next command run rebuilds the provider binding.
 * This is acceptable because the provider closure is lazy.
 *
 * `ORBIT_GATEWAY_IDENTITY` is removed per decision D2: production gateway API identity comes
 * only from WireGuard peer resolution and never from a CLI-supplied bearer.
 */
return [
    'gateway' => [
        'url' => env('ORBIT_GATEWAY_URL'),
        'ca_pem_path' => env('ORBIT_GATEWAY_CA_PEM'),
        'ca_sha256' => env('ORBIT_GATEWAY_CA_SHA256'),
        'timeout' => env('ORBIT_GATEWAY_TIMEOUT') !== null ? (int) env('ORBIT_GATEWAY_TIMEOUT') : null,
        'operation_follow_reconnect_sleep_ms' => env('ORBIT_GATEWAY_OPERATION_FOLLOW_RECONNECT_SLEEP_MS') !== null
            ? (int) env('ORBIT_GATEWAY_OPERATION_FOLLOW_RECONNECT_SLEEP_MS')
            : null,
        'operation_follow_max_empty_replays' => env('ORBIT_GATEWAY_OPERATION_FOLLOW_MAX_EMPTY_REPLAYS') !== null
            ? (int) env('ORBIT_GATEWAY_OPERATION_FOLLOW_MAX_EMPTY_REPLAYS')
            : null,
        'operation_follow_max_transient_failures' => env('ORBIT_GATEWAY_OPERATION_FOLLOW_MAX_TRANSIENT_FAILURES')
        !== null
            ? (int) env('ORBIT_GATEWAY_OPERATION_FOLLOW_MAX_TRANSIENT_FAILURES')
            : null,
    ],
    'local_executor_binary' => env('ORBIT_LOCAL_EXECUTOR_BINARY', default: '/usr/local/bin/orbit'),
];
