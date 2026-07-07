<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Enums\Nodes\NodeStatus;
use App\Models\App as OrbitApp;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Database\Eloquent\Builder;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class StaleToolIntentRemover
{
    public function __construct(
        private ToolCatalog $catalog,
        private NodeRoleAssignments $nodeRoleAssignments,
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

        $removedRoutes = $this->removeOwnedProxyRoutes($tool, $targetNode);

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
        $removedRoutes = $node instanceof Node ? $this->removeOwnedProxyRoutes($tool, $node) : 0;

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

        $model = OrbitApp::query()
            ->with('node')
            ->where(static function (Builder $query) use ($app): void {
                $query->where('name', $app)
                    ->orWhere('domain', $app);
            })
            ->first();

        if (! $model instanceof OrbitApp && str_contains($app, '.')) {
            [$appName, $nodeTld] = explode('.', $app, limit: 2);

            if ($appName !== '' && $nodeTld !== '') {
                $model = OrbitApp::query()
                    ->with('node')
                    ->where('name', $appName)
                    ->whereHas('node', function (Builder $query) use ($nodeTld): void {
                        $query
                            ->whereIn('id', $this->nodeRoleAssignments->activeAppHostNodeIds())
                            ->where('status', NodeStatus::Active->value)
                            ->where('tld', $nodeTld);
                    })
                    ->first();
            }
        }

        if (! $model instanceof OrbitApp || ! $model->node instanceof Node) {
            return null;
        }

        if (! $model->node->isActive() || ! $this->nodeRoleAssignments->nodeHasActiveAppHostRole($model->node)) {
            return null;
        }

        return $model->node;
    }

    private function removeOwnedProxyRoutes(string $tool, Node $node): int
    {
        $removed = 0;

        ProxyRoute::query()
            ->where('node_id', $node->id)
            ->where('owner_type', 'tool')
            ->get()
            ->each(static function (ProxyRoute $route) use ($tool, &$removed): void {
                $config = is_array($route->config) ? $route->config : [];

                if (($config['owner_name'] ?? null) !== $tool) {
                    return;
                }

                $route->delete();
                $removed++;
            });

        return $removed;
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
