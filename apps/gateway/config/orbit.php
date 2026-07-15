<?php

declare(strict_types=1);

$configRoot = env('ORBIT_CONFIG_ROOT');

if (! is_string($configRoot) || trim($configRoot) === '') {
    $home = getenv('HOME');

    if (! is_string($home) || trim($home) === '') {
        $home = '/home/orbit';
    }

    $configRoot = rtrim($home, '/').'/.config/orbit';
}

return [
    'e2e_topology_provider' => env('ORBIT_E2E_TOPOLOGY_PROVIDER'),
    'e2e_trust_wireguard_header' => env('ORBIT_E2E_TRUST_WIREGUARD_HEADER', false),
    'trust_wireguard_proxy_header' => env('ORBIT_TRUST_WIREGUARD_PROXY_HEADER', false),
    'forward_install_image_archives' => env('ORBIT_FORWARD_INSTALL_IMAGE_ARCHIVES', false),
    'forward_install_binary' => env('ORBIT_FORWARD_INSTALL_BINARY'),
    'local_executor_binary' => env('ORBIT_LOCAL_EXECUTOR_BINARY', '/usr/local/bin/orbit'),
    'operation_token_secret' => env('ORBIT_OPERATION_TOKEN_SECRET'),
    'operation_token_key_id' => env('ORBIT_OPERATION_TOKEN_KEY_ID'),
    'operation_token_previous_secret' => env('ORBIT_OPERATION_TOKEN_PREVIOUS_SECRET'),
    'operation_token_previous_key_id' => env('ORBIT_OPERATION_TOKEN_PREVIOUS_KEY_ID'),
    'operation_token_not_before_skew_seconds' => env('ORBIT_OPERATION_TOKEN_NOT_BEFORE_SKEW_SECONDS', default: 5),
    'operation_token_ttl_seconds' => env('ORBIT_OPERATION_TOKEN_TTL_SECONDS', 120),

    'gateway' => [
        'exposure_mode' => env('ORBIT_GATEWAY_EXPOSURE_MODE', 'router-colocated'),
    ],

    'paths' => [
        'config_root' => rtrim($configRoot, '/'),
    ],

    'cloudflare' => [
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
        'api_email' => env('CLOUDFLARE_API_EMAIL'),
    ],

    'operation_runs' => [
        'retention_days' => env('ORBIT_OPERATION_RUNS_RETENTION_DAYS', 90),
    ],

    'node_bootstrap' => [
        'readiness_attempts' => (int) env('ORBIT_NODE_BOOTSTRAP_READINESS_ATTEMPTS', 30),
        'readiness_delay_milliseconds' => (int) env('ORBIT_NODE_BOOTSTRAP_READINESS_DELAY_MILLISECONDS', 1000),
        'completion_lock_seconds' => (int) env('ORBIT_NODE_BOOTSTRAP_COMPLETION_LOCK_SECONDS', 3600),
        'completion_lock_wait_seconds' => (int) env('ORBIT_NODE_BOOTSTRAP_COMPLETION_LOCK_WAIT_SECONDS', 3600),
    ],

    'operations' => [
        'stream_auth_ttl_seconds' => (int) env('ORBIT_OPERATIONS_STREAM_AUTH_TTL_SECONDS', 300),
        'publisher_token_ttl_seconds' => (int) env(key: 'ORBIT_OPERATIONS_PUBLISHER_TOKEN_TTL_SECONDS', default: 120),
        'subscriber_lease_ttl_seconds' => (int) env(key: 'ORBIT_OPERATIONS_SUBSCRIBER_LEASE_TTL_SECONDS', default: 60),
        'reverb' => [
            'app_id' => env(key: 'ORBIT_OPERATIONS_REVERB_APP_ID', default: 'orbit-operations'),
            'app_key' => env('ORBIT_OPERATIONS_REVERB_APP_KEY'),
            'app_secret' => env('ORBIT_OPERATIONS_REVERB_APP_SECRET'),
            'host' => env(key: 'ORBIT_OPERATIONS_REVERB_HOST', default: 'orbit-operations-reverb'),
            'port' => (int) env(key: 'ORBIT_OPERATIONS_REVERB_PORT', default: 8080),
            'scheme' => env(key: 'ORBIT_OPERATIONS_REVERB_SCHEME', default: 'http'),
            'timeout_seconds' => (int) env(key: 'ORBIT_OPERATIONS_REVERB_TIMEOUT_SECONDS', default: 2),
        ],
    ],

    'artifacts' => [
        'disk' => env('ORBIT_ARTIFACTS_DISK', 'orbit-artifacts'),
        'base_url' => env('ORBIT_ARTIFACTS_BASE_URL'),
    ],

    'updates' => [
        'release_manifest_url' => env(
            'ORBIT_RELEASE_MANIFEST_URL',
            'https://github.com/hardimpactdev/orbit/releases/latest/download/orbit-release-manifest.json',
        ),
        'release_manifest_timeout_seconds' => env('ORBIT_RELEASE_MANIFEST_TIMEOUT_SECONDS', 10),
        'allow_request_image_override' => env('ORBIT_UPDATE_ALLOW_REQUEST_IMAGE_OVERRIDE', false),
        'artifact_base_url' => env('ORBIT_UPDATE_ARTIFACT_BASE_URL', env('APP_URL', 'http://localhost')),
        'artifact_cache_ttl_seconds' => env('ORBIT_UPDATE_ARTIFACT_CACHE_TTL_SECONDS', 86400),
        'artifact_download_timeout_seconds' => env(key: 'ORBIT_UPDATE_ARTIFACT_DOWNLOAD_TIMEOUT_SECONDS', default: 60),
        'gateway_image' => env('ORBIT_GATEWAY_IMAGE'),
        'gateway_image_archive' => env('ORBIT_GATEWAY_IMAGE_ARCHIVE'),
        'lease_ttl_seconds' => env(key: 'ORBIT_UPDATE_LEASE_TTL_SECONDS', default: 300),
        'manifest_snapshot' => [],
    ],
];
