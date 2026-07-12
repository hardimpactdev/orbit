<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\Node;
use App\Services\Nodes\NodeHostPaths;
use RuntimeException;

class NodeAgentConfigRenderer
{
    public function __construct(
        private readonly NodeHostPaths $hostPaths,
    ) {}

    public function render(Node $node, Node $gateway): string
    {
        if (! $node->isAgentEligible()) {
            throw new RuntimeException("Node [{$node->name}] is not eligible for managed Agent execution.");
        }

        return $this->renderConfig($node, $gateway);
    }

    public function renderForProvisioning(Node $node, Node $gateway): string
    {
        if (! $node->isAgentProvisioningReady()) {
            throw new RuntimeException("Node [{$node->name}] is not ready for provisioning Agent installation.");
        }

        return $this->renderConfig($node, $gateway);
    }

    private function renderConfig(Node $node, Node $gateway): string
    {
        if ($node->hasActiveRole('gateway')) {
            throw new RuntimeException('Gateway nodes are never Orbit Agent targets.');
        }

        $gatewayAddress = trim((string) $gateway->wireguard_address);

        if (! $gateway->hasActiveRole('gateway') || $gatewayAddress === '') {
            throw new RuntimeException('An active gateway WireGuard identity is required for Orbit Agent config.');
        }

        return implode("\n", [
            'gateway_url = "'.$this->tomlString("https://{$gatewayAddress}").'"',
            'node_id = "'.$this->tomlString((string) $node->getKey()).'"',
            'node_name = "'.$this->tomlString($node->name).'"',
            'gateway_name = "'.$this->tomlString($gateway->name).'"',
            'ca_pem_path = "'.$this->tomlString($this->caPath($node)).'"',
            'platform = "'.$this->tomlString((string) $node->platform).'"',
            'managed = true',
            'wireguard_address = "'.$this->tomlString((string) $node->wireguard_address).'"',
            '',
        ]);
    }

    public function path(Node $node): string
    {
        return $this->configRoot($node).'/agent.toml';
    }

    public function caPath(Node $node): string
    {
        return $this->configRoot($node).'/ca/root.crt';
    }

    private function configRoot(Node $node): string
    {
        return $this->hostPaths->homeDirectory($node).'/.config/orbit';
    }

    private function tomlString(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}
