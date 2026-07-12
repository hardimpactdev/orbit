<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Enums\Nodes\NodeStatus;
use App\Models\Node;
use App\Models\NodeTool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final readonly class ToolRegistry
{
    public function __construct(
        private ToolTargetSelectionResolver $targets,
        private ToolCatalog $catalog,
    ) {}

    /**
     * @return Collection<int, NodeTool>
     */
    public function list(?string $node = null, ?string $app = null): Collection
    {
        $targetNode = $this->targets->resolveFilter($node, $app);

        $tools = NodeTool::query()
            ->with('node')
            ->when(
                ! $targetNode instanceof Node,
                fn (Builder $query): Builder => $query->whereHas(
                    'node',
                    fn (Builder $query): Builder => $query->where('status', NodeStatus::Active->value),
                ),
            )
            ->when($targetNode instanceof Node, fn (Builder $query): Builder => $query->where(
                'node_id',
                $targetNode?->id,
            ))
            ->get();
        /** @var list<NodeTool> $visibleTools */
        $visibleTools = [];

        foreach ($tools as $tool) {
            if (! $tool instanceof NodeTool) {
                continue;
            }

            if ($tool->node instanceof Node && $this->catalog->supportsNode($tool->name, $tool->node)) {
                $visibleTools[] = $tool;
            }
        }

        usort(
            $visibleTools,
            static fn (NodeTool $first, NodeTool $second): int => (
                [
                    mb_strtolower((string) $first->node?->name),
                    mb_strtolower($first->name),
                ] <=> [
                    mb_strtolower((string) $second->node?->name),
                    mb_strtolower($second->name),
                ]
            ),
        );

        $collection = new Collection($visibleTools);

        /** @var Collection<int, NodeTool> $collection */
        return $collection;
    }

    public function show(
        string $tool,
        ?string $node = null,
        ?string $app = null,
        ?string $instance = null,
        ?string $version = null,
    ): NodeTool|ToolRegistryFailure {
        $targetNode = $this->targets->resolveTarget($tool, $node, $app);

        if ($targetNode instanceof ToolRegistryFailure) {
            return $targetNode;
        }

        $models = NodeTool::query()
            ->with('node')
            ->where('node_id', $targetNode->id)
            ->where('name', $tool)
            ->orderBy('name')
            ->get();

        if ($instance !== null) {
            return ToolRegistryFailure::notFound($tool, $targetNode->name);
        }

        if ($version !== null) {
            $models = $models
                ->filter(fn (NodeTool $model): bool => $model->expected_version === $version)
                ->values();
        }

        if ($models->isEmpty()) {
            return ToolRegistryFailure::notFound($tool, $targetNode->name);
        }

        return $models->first();
    }

    public function findStored(string $tool, ?string $node = null, ?string $app = null): ?NodeTool
    {
        $targetNode = $this->targets->resolveStored($node, $app);

        if (! $targetNode instanceof Node) {
            return null;
        }

        return NodeTool::query()
            ->with('node')
            ->where('node_id', $targetNode->id)
            ->where('name', $tool)
            ->first();
    }

    public function validateFilters(
        ?string $node = null,
        ?string $app = null,
        ?string $tool = null,
    ): ?ToolRegistryFailure {
        return $this->targets->validateFilters($node, $app, $tool);
    }
}
