<?php

declare(strict_types=1);

namespace App\Services\Solo;

use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Nodes\Roles\NodeRoleAssignments;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class SoloUpstreamTargetResolver
{
    public function __construct(
        private NodeRoleAssignments $roles,
    ) {}

    public function forNode(Node $node): SoloUpstreamTarget
    {
        if (! $this->roles->nodeIsGateway($node) && ! $node->isAgentEligible()) {
            throw new SoloProxyException(
                errorCode: 'validation_failed',
                message: "Solo on {$node->name} requires an active Agent-eligible target node.",
                meta: [
                    'reason' => 'solo_target_agent_required',
                    'node' => $node->name,
                ],
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

        $credentials = $tool?->credentials;
        $bearerToken = is_array($credentials) && is_string($credentials['bearer_token'] ?? null)
            ? trim($credentials['bearer_token'])
            : null;

        if (
            ($bearerToken === null
            || $bearerToken === '')
            && is_array($config)
            && is_string($config['bearer_token'] ?? null)
        ) {
            $bearerToken = trim($config['bearer_token']);
        }

        return new SoloUpstreamTarget(
            node: $node,
            url: rtrim($url, characters: '/'),
            identity: $identity,
            bearerToken: $bearerToken !== '' ? $bearerToken : null,
        );
    }
}
