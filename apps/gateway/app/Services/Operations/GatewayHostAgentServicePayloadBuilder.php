<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\Node;
use App\Services\Gateway\GatewayHostAgentConfigWriter;
use App\Services\Nodes\NodeHostPaths;

final readonly class GatewayHostAgentServicePayloadBuilder
{
    public function __construct(
        private ?GatewayHostAgentConfigWriter $configs = null,
    ) {}

    /**
     * @return array{unit_name: string, exec_start: string, config_path: string, http_bind: string, user: string}|null
     */
    public function forNode(Node $gatewayNode): ?array
    {
        if (NodeHostPaths::isMacosPlatform($gatewayNode->platform)) {
            return null;
        }

        $wireguardAddress = is_string($gatewayNode->wireguard_address)
            ? trim($gatewayNode->wireguard_address)
            : '';

        if ($wireguardAddress === '') {
            return null;
        }

        return [
            'unit_name' => 'orbit-agent',
            'exec_start' => FleetUpdateNodeAgentBinary::binPath($gatewayNode),
            'config_path' => $this->configs()->path(),
            'http_bind' => "{$wireguardAddress}:9477",
            'user' => $this->user($gatewayNode),
        ];
    }

    private function user(Node $gatewayNode): string
    {
        return is_string($gatewayNode->user) && trim($gatewayNode->user) !== ''
            ? trim($gatewayNode->user)
            : 'orbit';
    }

    private function configs(): GatewayHostAgentConfigWriter
    {
        return $this->configs ?? app(GatewayHostAgentConfigWriter::class);
    }
}
