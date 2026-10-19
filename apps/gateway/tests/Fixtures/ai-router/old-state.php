<?php

declare(strict_types=1);
use App\Models\Node;

// Recorded Gateway shape on 2026-10-18. Credential and digest bytes are synthetic.
return [
    'settings' => [
        'extension.proxycli.enabled' => '1',
        'proxycli.enabled' => '1',
        'proxycli.node_id' => '17',
        'proxycli.cache_connection' => 'cache',
        'proxycli.cliproxy_url' => 'http://10.44.0.3:8317',
        'proxycli.cliproxy_management_key' => 'synthetic-management',
        'proxycli.read_token' => 'synthetic-read',
        'proxycli.control_token' => 'synthetic-control',
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
    'footprint' => ['proxycli' => 'recorded-collector-digest', 'caddy' => 'recorded-caddy-digest'],
    'certificate' => ['key' => 'caddy.certificate.proxycli', 'value' => '2026-10-18T16:22:29+00:00'],
];
