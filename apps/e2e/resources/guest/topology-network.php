#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Infrastructure\WireGuard\WireGuardServerConfigRenderer;
use Symfony\Component\Process\Process;

require '/home/orbit/orbit/apps/gateway/vendor/autoload.php';

umask(0077);
try {
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $nodes = $input['nodes'] ?? null;
    if ($argc !== 1 || ! is_array($nodes) || ! array_is_list($nodes) || count($nodes) < 2 || ($nodes[0]['key'] ?? null) !== 'gateway') {
        throw new RuntimeException;
    }
    $peers = [];
    foreach ($nodes as $node) {
        if (! is_string($node['wireguard_public_key'] ?? null)
            || preg_match('/\A[A-Za-z0-9+\/]{43}=\z/D', $node['wireguard_public_key']) !== 1
            || ! is_string($node['wireguard_ip'] ?? null)
            || preg_match('/\A10\.44\.0\.([1-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-4])\z/D', $node['wireguard_ip']) !== 1
            || ! is_string($node['key'] ?? null) || preg_match('/\A[a-z][a-z0-9-]{0,22}\z/D', $node['key']) !== 1) {
            throw new RuntimeException;
        }
        if ($node['key'] !== 'gateway') {
            $peers[] = ['name' => $node['key'], 'public_key' => $node['wireguard_public_key'], 'address' => $node['wireguard_ip'].'/32'];
        }
    }
    $key = '/etc/wireguard/orbit.key';
    if (is_link($key) || ! is_file($key)) {
        throw new RuntimeException;
    }
    $private = trim(file_get_contents($key));
    $public = new Process(['/usr/bin/wg', 'pubkey'], input: $private, timeout: 10);
    if ($public->run() !== 0 || trim($public->getOutput()) !== $nodes[0]['wireguard_public_key']) {
        throw new RuntimeException;
    }
    $configuration = (new WireGuardServerConfigRenderer)->renderForPeers('10.44.0.1/24', 51820, $private, $peers);
    $candidate = tempnam('/etc/wireguard', '.orbit-fixture-');
    try {
        if (file_put_contents($candidate, $configuration) === false || ! chmod($candidate, 0600)
            || ! rename($candidate, '/etc/wireguard/orbit.conf')) {
            throw new RuntimeException;
        }
    } finally {
        if (file_exists($candidate)) {
            unlink($candidate);
        }
    }
    echo "{\"configured\":true}\n";
} catch (Throwable) {
    fwrite(STDERR, "e2e.fixture_network_hub_failed\n");
    exit(1);
}
