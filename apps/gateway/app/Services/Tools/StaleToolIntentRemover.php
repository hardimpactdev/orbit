<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Proxy\ProxyRouteFixer;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class StaleToolIntentRemover
{
    public function __construct(
        private ToolCatalog $catalog,
        private ToolAppNodeResolver $instanceNodes,
        private NodeRoleAssignments $nodeRoleAssignments,
        private ProxyRouteFixer $proxyRouteFixer,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function withoutRecord(string $tool, ?string $node, ?string $app): ?array
    {
        $targetNode = $this->targetNode($node, $app);

        if (! $targetNode instanceof Node) {
            return null;
        }

        if ($this->catalog->supports($tool) && $this->catalog->supportsNode($tool, $targetNode)) {
            return null;
        }

        $removedRoutes = $this->removeOwnedProxyRoutesFor($tool, $targetNode);

        if ($removedRoutes === 0) {
            return null;
        }

        return [
            'name' => $tool,
            'node' => $targetNode->name,
            'stale_record' => true,
            'stale_routes_removed' => $removedRoutes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function withRecord(string $tool, NodeTool $model): array
    {
        $node = $model->node;
        $removedRoutes = $node instanceof Node ? $this->removeOwnedProxyRoutesFor($tool, $node) : 0;

        $model->credentials = null;
        $model->save();
        $model->delete();

        return [
            'name' => $tool,
            'node' => $node?->name,
            'stale_record' => true,
            'stale_routes_removed' => $removedRoutes,
        ];
    }

    /**
     * Remove backend/TLS for tool-owned proxy routes, then delete registry rows.
     * Order matches proxy:remove --force so a failed cleanup leaves intent for retry.
     */
    public function removeOwnedProxyRoutesFor(string $tool, Node $node): int
    {
        $removed = 0;

        foreach (ProxyRoute::query()
            ->where('node_id', $node->id)
            ->where('owner_type', 'tool')
            ->get() as $route) {
            $config = is_array($route->config) ? $route->config : [];

            if (($config['owner_name'] ?? null) !== $tool) {
                continue;
            }

            // Backend/TLS first (same ordering as ProxyRouteIntent::remove).
            $this->proxyRouteFixer->removeExtra($node, $route->domain);
            $route->delete();
            $removed++;
        }

        return $removed;
    }

    private function targetNode(?string $node, ?string $app): ?Node
    {
        if ($node !== null) {
            /** @var Node|null */
            return Node::query()
                ->where('name', $node)
                ->where('status', NodeStatus::Active->value)
                ->whereIn('id', $this->nodeRoleAssignments->activeToolHostNodeIds())
                ->whereNotIn('id', $this->gatewayNodeIds())
                ->first();
        }

        if ($app === null) {
            return null;
        }

        return $this->instanceNodes->resolve($app);
    }

    /**
     * @return list<int>
     */
    private function gatewayNodeIds(): array
    {
        return array_values(
            $this->nodeRoleAssignments
                ->activeGatewayNodeQuery()
                ->pluck('id')
                ->map(static fn (mixed $nodeId): int => (int) $nodeId)
                ->all(),
        );
    }
}
