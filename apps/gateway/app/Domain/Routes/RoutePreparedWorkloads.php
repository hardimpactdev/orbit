<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Models\Instance;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;

/** Read-only staging workload sites projected by the admitted native target-set run. */
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
                || $operation->target_id !== $owner->id) {
                continue;
            }
            if (($operation->state['native_target_preparations'] ?? false) !== true) {
                continue;
            }
            $intent = ValidatedData::object($operation->input['route_target'] ?? []);
            foreach (array_map(ValidatedData::object(...), $intent['prepared'] ?? []) as $target) {
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
}
