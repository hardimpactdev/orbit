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

        return [
            'unit_name' => 'orbit-agent',
            'exec_start' => FleetUpdateNodeAgentBinary::binPath($node),
            'config_path' => $this->configs->path($node),
            'config' => $this->configs->render($node, $gateway),
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
