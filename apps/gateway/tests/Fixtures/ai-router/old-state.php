<?php

declare(strict_types=1);
use App\Models\Node;

// Pending cutover after the schema baseline, before the first runtime tick. All secrets are synthetic.
return [
    'settings' => [
        'extension.ai-router.enabled' => '1',
        'ai_router.enabled' => '1',
        'ai_router.node_id' => '17',
        'ai_router.cache_connection' => 'cache',
        'ai_router.router_url' => 'http://10.44.0.3:8317',
        'ai_router.management_key' => 'synthetic-management',
        'ai_router.read_token' => 'synthetic-read',
        'ai_router.control_token' => 'synthetic-control',
    ],
    'environment' => [
        'PROXYCLI_CLIPROXY_URL' => 'http://10.44.0.3:8317',
        'PROXYCLI_MANAGEMENT_KEY' => 'synthetic-management',
        'PROXYCLI_READ_TOKEN' => 'synthetic-read',
        'PROXYCLI_CONTROL_TOKEN' => 'synthetic-control',
        'PROXYCLI_CACHE_HOST' => '127.0.0.1',
        'PROXYCLI_CACHE_PORT' => '6379',
        'PROXYCLI_CACHE_USERNAME' => '',
        'PROXYCLI_CACHE_PASSWORD' => '',
        'PROXYCLI_PORT' => '8787',
    ],
    'collector' => [
        'id' => 163, 'owner_type' => Node::class, 'owner_id' => 17,
        'name' => 'cli-proxy-api-collector', 'runtime' => 'systemd',
        'working_directory' => '/var/lib/orbit/proxycli',
        'desired_state' => 'running', 'status' => 'active',
    ],
    'command' => ['/usr/bin/python3', '/var/lib/orbit/proxycli/server.py'],
    'footprint' => ['caddy' => 'recorded-caddy-digest'],
];
