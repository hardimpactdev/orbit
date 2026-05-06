<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

use App\Models\App;
use App\Models\Node;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     * @return array{node: ?string, app: ?string}|JsonResponse
     */
    private function authorizedToolTarget(Request $request, Node $caller, array $visibleNodeIds): array|JsonResponse
    {
        $node = $this->toolTargetString($request, 'node');
        $app = $this->toolTargetString($request, 'app');
        $nodeFilter = null;

        if ($node !== null) {
            $nodeFilter = $this->resolveNodeFilter($node, $caller, $visibleNodeIds);

            if (! $nodeFilter instanceof Node) {
                return $this->toolTargetFailure($node, 'node', $caller, $visibleNodeIds);
            }
        }

        if ($app !== null) {
            $appNode = $this->resolveAppNodeFilter($app, $caller, $visibleNodeIds);

            if (! $appNode instanceof Node) {
                return $this->toolTargetFailure($app, 'app', $caller, $visibleNodeIds);
            }

            if ($nodeFilter instanceof Node && $nodeFilter->id !== $appNode->id) {
                return $this->toolTargetValidationFailed('app', $app, "Invalid value for --app: '{$app}'. App is not owned by the selected node.");
            }

            $nodeFilter = $appNode;
        }

        if ($nodeFilter instanceof Node) {
            return [
                'node' => $node,
                'app' => $app,
            ];
        }

        if ($caller->role !== 'gateway') {
            $nodes = Node::query()
                ->whereIn('id', $visibleNodeIds)
                ->where('role', 'app')
                ->where('status', 'active')
                ->orderBy('name')
                ->limit(2)
                ->get();

            if ($nodes->count() === 1) {
                return [
                    'node' => $nodes->first()->name,
                    'app' => null,
                ];
            }
        }

        return [
            'node' => null,
            'app' => null,
        ];
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

    private function toolTargetString(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  list<int>  $visibleNodeIds
     */
    private function toolTargetFailure(string $value, string $field, Node $caller, array $visibleNodeIds): JsonResponse
    {
        if ($caller->role !== 'gateway' && $this->toolTargetExists($field, $value, $visibleNodeIds)) {
            return $this->toolTargetAuthorizationFailed("This node is not authorized to manage tools for the selected {$field}.", [
                $field => $value,
            ]);
        }

        $expected = $field === 'node'
            ? 'Expected a visible app node name.'
            : 'Expected a visible app name or domain.';

        return $this->toolTargetValidationFailed($field, $value, "Invalid value for --{$field}: '{$value}'. {$expected}");
    }

    /**
     * @param  list<int>  $visibleNodeIds
     */
    private function toolTargetExists(string $field, string $value, array $visibleNodeIds): bool
    {
        if ($field === 'node') {
            return Node::query()
                ->where('name', $value)
                ->where('role', 'app')
                ->where('status', 'active')
                ->whereNotIn('id', $visibleNodeIds)
                ->exists();
        }

        return App::query()
            ->where(function (Builder $query) use ($value): void {
                $query->where('name', $value)
                    ->orWhere('domain', $value);
            })
            ->whereHas('node', function (Builder $query) use ($visibleNodeIds): void {
                $query->where('role', 'app')
                    ->where('status', 'active')
                    ->whereNotIn('id', $visibleNodeIds);
            })
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function toolTargetAuthorizationFailed(string $message, array $meta = []): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => $message,
                'meta' => $meta === [] ? (object) [] : $meta,
            ],
        ], 403);
    }

    private function toolTargetValidationFailed(string $field, string $value, string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => $message,
                'meta' => [
                    'field' => $field,
                    'value' => $value,
                ],
            ],
        ], 422);
    }
}
