<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\GuestCommand;
use App\E2E\Value\TopologyTarget;
use RuntimeException;

/** Fixture-only tunnel setup, using canonical hub rendering and peer actions. */
final readonly class TopologyFixtureNetwork
{
    public function __construct(private IncusHost $host) {}

    /** @param list<array<string, mixed>> $nodes Public capture, with private keys retained on their own VMs. */
    public function converge(TopologyTarget $target, array $nodes): void
    {
        if (array_column($nodes, 'key') !== $target->recipe->nodeKeys()) {
            throw new RuntimeException('e2e.fixture_network_nodes_invalid.');
        }
        $facts = [];
        foreach ($nodes as $node) {
            if (! is_string($node['key'] ?? null) || ! is_string($node['wireguard_ip'] ?? null)
                || ! is_string($node['wireguard_public_key'] ?? null) || ! is_string($node['public_address'] ?? null)) {
                throw new RuntimeException('e2e.fixture_network_nodes_invalid.');
            }
            $facts[] = $node;
        }
        $gateway = $facts[0];
        $result = $this->host->exec($target->instance('gateway'), new GuestCommand(
            ['php', '/usr/local/bin/topology-network.php'], 60, json_encode(['nodes' => $nodes], JSON_THROW_ON_ERROR),
        ));
        if (! $result->successful() || json_decode($result->stdout, true) !== ['configured' => true]) {
            throw new RuntimeException('e2e.fixture_network_hub_failed.');
        }
        foreach (array_slice($facts, 1) as $node) {
            $this->peer($target->instance($node['key']), 'peer_config', [
                'public_key' => $node['wireguard_public_key'],
                'peer' => ['address' => $node['wireguard_ip'].'/24', 'server_public_key' => $gateway['wireguard_public_key'],
                    'endpoint' => $gateway['public_address'].':51820', 'subnet' => '10.44.0.0/24'],
            ], 'configured');
        }
        foreach ($facts as $node) {
            $this->peer($target->instance($node['key']), 'apply_peer', [], 'applied');
            $this->peer($target->instance($node['key']), 'dns_persist', [
                'public_key' => $node['wireguard_public_key'], 'server' => '10.44.0.1', 'domains' => ['orbit'],
            ], 'persisted');
        }
    }

    /** @param array<string, mixed> $input */
    private function peer(string $instance, string $action, array $input, string $field): void
    {
        $source = dirname(__DIR__, 4).'/swamp/extensions/behaviors/operations/network-command/scripts/';
        $common = file_get_contents($source.'common.py');
        $script = file_get_contents($source.$action.'.py');
        if ($common === false || $script === false) {
            throw new RuntimeException('e2e.fixture_network_assets_missing.');
        }
        $program = str_replace('NETWORK_INPUT = None', 'NETWORK_INPUT = json.loads(__import__("sys").stdin.read())', $common)
            ."\n".preg_replace('/^from common import .*\n/m', '', $script);
        $result = $this->host->exec($instance, new GuestCommand(
            ['python3', '-I', '-c', $program], 60, json_encode((object) $input, JSON_THROW_ON_ERROR),
        ));
        if (! $result->successful() || json_decode($result->stdout, true) !== [$field => true]) {
            throw new RuntimeException('e2e.fixture_network_'.$action.'_failed.');
        }
    }
}
