<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Models\Instance;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;

/** Read-only workload sites named by a child-proven target-set preparation. */
final readonly class RoutePreparedWorkloads
{
    /** @return list<array{instance:Instance,route:Route}> */
    public function sites(?int $nodeId): array
    {
        $sites = [];
        foreach (Route::query()->whereNotNull('target_set_intent')->get() as $owner) {
            $id = $owner->target_set_intent['preparation_operation_id'] ?? null;
            $operation = is_int($id) ? Operation::query()->find($id) : null;
            if ($operation === null || $operation->kind !== 'route.target_set' || $operation->target_type !== 'route'
                || $operation->target_id !== $owner->id || isset($operation->state['route_branch'])) {
                continue;
            }
            $completed = ValidatedData::object($operation->state['route_completed_stages'] ?? []);
            if (($completed['target-workload-certificates'] ?? false) !== true || ($completed['target-live-certificates'] ?? false) === true) {
                continue;
            }
            $plan = ValidatedData::object($operation->state['route_plan'] ?? []);
            foreach (RouteExecutionProof::objects($plan['prepared_targets'] ?? []) as $target) {
                $instance = Instance::query()->with(['node', 'project'])->find(ValidatedData::integer($target['instance_id']));
                $route = Route::query()->with('cluster.routerAssignment')->find(ValidatedData::integer($target['route_id']));
                if ($instance === null || $route === null || $instance->node_id !== $target['node_id']
                    || ($nodeId !== null && $instance->node_id !== $nodeId) || $route->cluster?->routerAssignment?->node_id === $instance->node_id) {
                    continue;
                }
                $sites[] = ['instance' => $instance, 'route' => $route];
            }
        }

        return $sites;
    }

    public function contains(Operation $child, Route $route, Instance $instance): bool
    {
        $parent = $child->parent_operation_id === null ? null : Operation::query()->find($child->parent_operation_id);
        if ($parent === null || $parent->kind !== 'route.target_set' || $parent->target_type !== 'route') {
            return false;
        }
        $plan = ValidatedData::object($parent->state['route_plan'] ?? []);

        return array_any(RouteExecutionProof::objects($plan['prepared_targets'] ?? []), static fn (array $target): bool => $target['route_id'] === $route->id && $target['instance_id'] === $instance->id && $target['node_id'] === $instance->node_id);
    }
}
