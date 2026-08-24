<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\Node;
use App\Services\Ca\OrbitCaService;

final readonly class NodeAgentServicePayloadBuilder
{
    public function __construct(
        private NodeAgentConfigRenderer $configs,
        private OrbitCaService $ca,
    ) {}

    /**
     * @return array{unit_name: string, exec_start: string, config_path: string, config: string, ca_path: string, ca_pem: string, http_bind: string, user: string}|null
     */
    public function forNode(Node $node, Node $gateway): ?array
    {
        if (! $node->isAgentEligible()) {
            return null;
        }

        return $this->payload($node, $gateway, $this->configs->render($node, $gateway));
    }

    /**
     * @return array{unit_name: string, exec_start: string, config_path: string, config: string, ca_path: string, ca_pem: string, http_bind: string, user: string}
     */
    public function forFleetUpdateNode(Node $node, Node $gateway): array
    {
        return $this->payload($node, $gateway, $this->configs->renderForFleetUpdate($node, $gateway));
    }

    /**
     * @return array{unit_name: string, exec_start: string, config_path: string, config: string, ca_path: string, ca_pem: string, http_bind: string, user: string}
     */
    public function forProvisioningNode(Node $node, Node $gateway): array
    {
        return $this->payload($node, $gateway, $this->configs->renderForProvisioning($node, $gateway));
    }

    /**
     * @return array{unit_name: string, exec_start: string, config_path: string, config: string, ca_path: string, ca_pem: string, http_bind: string, user: string}
     */
    private function payload(Node $node, Node $gateway, string $config): array
    {
        return [
            'unit_name' => 'orbit-agent',
            'exec_start' => FleetUpdateNodeAgentBinary::binPath($node),
            'config_path' => $this->configs->path($node),
            'config' => $config,
            'ca_path' => $this->configs->caPath($node),
            'ca_pem' => $this->ca->rootCert(),
            'http_bind' => trim((string) $node->wireguard_address).':9477',
            'user' => $this->user($node),
        ];
    }

    private function user(Node $node): string
    {
        return is_string($node->user) && trim($node->user) !== ''
            ? trim($node->user)
            : 'orbit';
    }
}
