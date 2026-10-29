<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;

/** Verifies Route-child ownership from admitted sets, including targets already detached. */
final readonly class InstanceRouteOwnership
{
    public function route(Operation $operation): ?Route
    {
        $parentId = $operation->getAttribute('parent_operation_id');
        $parent = is_int($parentId) ? Operation::query()->findOrFail($parentId) : null;
        if ($parent === null || ! str_starts_with($parent->kind, 'route.')) {
            return null;
        }
        if (! in_array($parent->kind, ['route.create', 'route.update', 'route.destroy', 'route.target_set', 'route.target_unset',
            'route.converge', 'route.withdraw', 'route.retire_source'], true)
            || $parent->target_type !== 'route' || ($parent->input['route_id'] ?? null) !== $parent->target_id
            || $operation->target_type !== 'instance' || ($operation->input['instance_id'] ?? null) !== $operation->target_id) {
            throw $this->invalid();
        }
        $plan = ValidatedData::object($parent->state['route_plan'] ?? []);
        $ids = [];
        foreach (['target_instance_ids', 'previous_target_instance_ids', 'removed_instance_ids'] as $field) {
            $ids = [...$ids, ...ValidatedData::integerList($parent->input[$field] ?? []), ...ValidatedData::integerList($plan[$field] ?? [])];
        }
        if (is_int($parent->input['instance_id'] ?? null)) {
            $ids[] = $parent->input['instance_id'];
        }
        $route = Route::query()->findOrFail($parent->target_id);
        if (! in_array($operation->target_id, $ids, true) && ! $route->targets()->where('instance_id', $operation->target_id)->exists()) {
            throw $this->invalid();
        }
        $instance = Instance::query()->findOrFail($operation->target_id);
        if ($instance->node_id !== $operation->node_id || $instance->project_id !== $route->project_id) {
            throw $this->invalid();
        }
        $candidateId = $parent->input['candidate_route_id'] ?? null;
        if (is_int($candidateId) && ($operation->input['route_domain'] ?? null) === InstanceEnvironmentRouteDomain::Candidate->value) {
            $candidate = Route::query()->findOrFail($candidateId);
            if ($candidate->replaces_route_id !== $route->id || $candidate->project_id !== $route->project_id) {
                throw $this->invalid();
            }

            return $candidate;
        }

        return $route;
    }

    /** Include an admitted prospective target before its RouteTarget association exists. */
    public function preparingRoute(Operation $operation): ?Route
    {
        $route = $this->route($operation);
        $parentId = $operation->getAttribute('parent_operation_id');
        $parent = is_int($parentId) ? Operation::query()->findOrFail($parentId) : null;
        $candidateId = $parent?->input['candidate_route_id'] ?? null;
        if ($route !== null && is_int($candidateId) && $parent->kind === 'route.update') {
            $candidate = Route::query()->findOrFail($candidateId);
            if ($candidate->project_id !== $route->project_id || $candidate->replaces_route_id !== $route->id) {
                throw $this->invalid();
            }
            if (in_array($candidate->status->value, ['pending', 'activating'], true)) {
                $route = $candidate;
            }
        }
        if ($route === null || ! $route->hasWebRoot() || ! in_array($route->status->value, ['pending', 'activating', 'active'], true)) {
            return null;
        }
        if ($parent === null) {
            throw $this->invalid();
        }
        if (in_array($parent->kind, ['route.destroy', 'route.withdraw', 'route.retire_source', 'route.target_unset'], true)) {
            return null;
        }
        $plan = ValidatedData::object($parent->state['route_plan'] ?? []);
        $proposed = ValidatedData::integerList($plan['target_instance_ids'] ?? $parent->input['target_instance_ids'] ?? []);

        return in_array($operation->target_id, $proposed, true)
            || ($parent->input['instance_id'] ?? null) === $operation->target_id ? $route : null;
    }

    public function onlyUrls(Operation $operation): bool
    {
        $key = $operation->getAttribute('child_key');

        return $this->route($operation) !== null && is_string($key)
            && ($key === 'synchronize-web-root-urls' || str_starts_with($key, 'synchronize-web-root-urls.'));
    }

    private function invalid(): ResourceOperationException
    {
        return new ResourceOperationException('operation.execution_forbidden', 'The Route parent does not own this Instance effect.', 422);
    }
}
