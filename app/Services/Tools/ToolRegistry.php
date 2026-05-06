<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Models\App;
use App\Models\Node;
use App\Models\NodeTool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final readonly class ToolRegistry
{
    /**
     * @return Collection<int, NodeTool>
     */
    public function list(?string $node = null, ?string $app = null): Collection
    {
        $targetNode = $this->resolveNodeFilter($node, $app);

        return NodeTool::query()
            ->with('node')
            ->whereHas('node', fn (Builder $query): Builder => $this->visibleAppNodeQuery($query))
            ->when($targetNode instanceof Node, fn (Builder $query): Builder => $query->where('node_id', $targetNode->id))
            ->get()
            ->sort(fn (NodeTool $first, NodeTool $second): int => [
                mb_strtolower((string) $first->node?->name),
                mb_strtolower($first->name),
            ] <=> [
                mb_strtolower((string) $second->node?->name),
                mb_strtolower($second->name),
            ])
            ->values();
    }

    public function show(string $tool, ?string $node = null, ?string $app = null): NodeTool|ToolRegistryFailure
    {
        $targetNode = $this->resolveTargetNode($node, $app);

        if ($targetNode instanceof ToolRegistryFailure) {
            return $targetNode;
        }

        $model = NodeTool::query()
            ->with('node')
            ->where('node_id', $targetNode->id)
            ->where('name', $tool)
            ->first();

        if (! $model instanceof NodeTool) {
            return ToolRegistryFailure::notFound($tool, $targetNode->name);
        }

        return $model;
    }

    public function validateFilters(?string $node = null, ?string $app = null): ?ToolRegistryFailure
    {
        $nodeFilter = null;

        if ($node !== null) {
            $nodeFilter = $this->resolveNode($node);

            if (! $nodeFilter instanceof Node) {
                return ToolRegistryFailure::validation('node', $node, "Invalid value for --node: '{$node}'. Expected a visible app node name.");
            }
        }

        if ($app !== null) {
            $appNode = $this->resolveAppNode($app);

            if (! $appNode instanceof Node) {
                return ToolRegistryFailure::validation('app', $app, "Invalid value for --app: '{$app}'. Expected a visible app name or domain.");
            }

            if ($nodeFilter instanceof Node && $nodeFilter->id !== $appNode->id) {
                return ToolRegistryFailure::validation('app', $app, "Invalid value for --app: '{$app}'. App is not owned by the selected node.");
            }
        }

        return null;
    }

    private function resolveTargetNode(?string $node, ?string $app): Node|ToolRegistryFailure
    {
        $validation = $this->validateFilters($node, $app);

        if ($validation instanceof ToolRegistryFailure) {
            return $validation;
        }

        $targetNode = $this->resolveNodeFilter($node, $app);

        if ($targetNode instanceof Node) {
            return $targetNode;
        }

        $nodes = Node::query()
            ->where('role', 'app')
            ->where('status', 'active')
            ->orderBy('name')
            ->limit(2)
            ->get();

        if ($nodes->count() === 1) {
            return $nodes->first();
        }

        return ToolRegistryFailure::validation('node', '', 'A node or app filter is required when the visible tool target is ambiguous.');
    }

    private function resolveNodeFilter(?string $node, ?string $app): ?Node
    {
        if ($node !== null) {
            return $this->resolveNode($node);
        }

        if ($app !== null) {
            return $this->resolveAppNode($app);
        }

        return null;
    }

    private function resolveNode(?string $node): ?Node
    {
        if ($node === null) {
            return null;
        }

        return Node::query()
            ->where('name', $node)
            ->where('role', 'app')
            ->where('status', 'active')
            ->first();
    }

    private function resolveAppNode(?string $app): ?Node
    {
        if ($app === null) {
            return null;
        }

        $model = App::query()
            ->with('node')
            ->where(function (Builder $query) use ($app): void {
                $query->where('name', $app)
                    ->orWhere('domain', $app);
            })
            ->first();

        if (! $model instanceof App || ! $model->node instanceof Node) {
            return null;
        }

        if ($model->node->role !== 'app' || $model->node->status !== 'active') {
            return null;
        }

        return $model->node;
    }

    private function visibleAppNodeQuery(Builder $query): Builder
    {
        return $query
            ->where('role', 'app')
            ->where('status', 'active');
    }
}
