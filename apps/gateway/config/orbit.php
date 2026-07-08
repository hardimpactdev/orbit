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
        'artifact_download_timeout_seconds' => env('ORBIT_UPDATE_ARTIFACT_DOWNLOAD_TIMEOUT_SECONDS', 60),
        'gateway_image' => env('ORBIT_GATEWAY_IMAGE'),
        'gateway_image_archive' => env('ORBIT_GATEWAY_IMAGE_ARCHIVE'),
        'lease_ttl_seconds' => env('ORBIT_UPDATE_LEASE_TTL_SECONDS', 300),
        'manifest_snapshot' => [],
    ],
];
