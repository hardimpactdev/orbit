<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

use App\Models\App;
use App\Models\Node;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

trait ResolvesVisibleToolNodes
{
    /**
     * @return list<int>
     */
    private function visibleToolNodeIds(Node $caller): array
    {
        if ($caller->role === 'gateway') {
            return Node::query()
                ->where('role', 'app')
                ->where('status', 'active')
                ->pluck('id')
                ->all();
        }

        return DB::table('node_access')
            ->join('nodes', 'nodes.id', '=', 'node_access.serving_node_id')
            ->where('node_access.consumer_node_id', $caller->id)
            ->where('nodes.role', 'app')
            ->where('nodes.status', 'active')
            ->pluck('nodes.id')
            ->all();
    }

    /**
     * @param  list<int>  $visibleNodeIds
     */
    private function resolveNodeFilter(string $node, Node $caller, array $visibleNodeIds): ?Node
    {
        return Node::query()
            ->where('name', $node)
            ->where('role', 'app')
            ->where('status', 'active')
            ->when($caller->role !== 'gateway', fn (Builder $query): Builder => $query->whereIn('id', $visibleNodeIds))
            ->first();
    }

    /**
     * @param  list<int>  $visibleNodeIds
     */
    private function resolveAppNodeFilter(string $app, Node $caller, array $visibleNodeIds): ?Node
    {
        $model = App::query()
            ->with('node')
            ->when($caller->role !== 'gateway', fn (Builder $query): Builder => $query->whereIn('node_id', $visibleNodeIds))
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
}
