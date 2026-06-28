<?php

declare(strict_types=1);

namespace App\Services\Solo;

use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Nodes\Roles\NodeRoleAssignments;

final readonly class SoloUpstreamTargetResolver
{
    public function __construct(
        private NodeRoleAssignments $roles,
    ) {}

    public function gatewayTarget(?Node $node = null): SoloUpstreamTarget
    {
        if (! $node instanceof Node) {
            /** @var Node|null $node */
            $node = $this->roles
                ->activeGatewayNodeQuery()
                ->first();
        }

        if (! $node instanceof Node) {
            throw new SoloProxyException(
                errorCode: 'validation_failed',
                message: 'A gateway node is required before Solo can be proxied.',
                meta: ['reason' => 'gateway_node_missing'],
                status: 422,
            );
        }

        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'solo')
            ->first();

        $config = $tool?->config;
        $url = is_array($config) && is_string($config['api_url'] ?? null)
            ? trim($config['api_url'])
            : '';

        if ($url === '') {
            throw new SoloProxyException(
                errorCode: 'validation_failed',
                message: "Solo API is not configured on {$node->name}.",
                meta: [
                    'reason' => 'solo_api_not_configured',
                    'node' => $node->name,
                ],
                status: 422,
            );
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || ! in_array($host, ['127.0.0.1', 'localhost', '::1'], strict: true)) {
            throw new SoloProxyException(
                errorCode: 'validation_failed',
                message: "Solo API for {$node->name} must be configured as a node-local loopback URL.",
                meta: [
                    'reason' => 'solo_api_url_not_loopback',
                    'node' => $node->name,
                ],
                status: 422,
            );
        }

        $identity =
            is_array($config) && is_string($config['node_identity'] ?? null) && trim($config['node_identity']) !== ''
                ? trim($config['node_identity'])
                : $node->name;

        return new SoloUpstreamTarget(
            node: $node,
            url: rtrim($url, characters: '/'),
            identity: $identity,
        );
    }
}
