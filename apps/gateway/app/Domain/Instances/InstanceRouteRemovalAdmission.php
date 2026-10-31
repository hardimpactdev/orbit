<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;

/**
 * Route target-set removal derives its authority from the admitted pool and proven publication.
 *
 * @phpstan-import-type ChildIntent from OperationFamilyChildren
 */
final readonly class InstanceRouteRemovalAdmission
{
    /** @param ChildIntent $intent */
    public function instance(Operation $parent, array $intent): Instance
    {
        $id = $intent['input']['instance_id'] ?? null;
        if (! is_int($id) || ! in_array($intent['kind'], ['instance.destroy', 'instance.destroy_development'], true) || $intent['key'] !== 'removal.'.$id
            || $intent['input'] !== ['instance_id' => $id, 'force' => true]) {
            throw $this->forbidden();
        }
        $instance = Instance::query()->with(['node', 'project'])->find($id);
        if ($instance === null || $instance->node_id !== $intent['node_id'] || $intent['kind'] !== InstanceDestroyKind::forInstance($instance)) {
            throw $this->forbidden();
        }
        $this->assertAllowed($parent, $instance, true);

        return $instance;
    }

    public function assertAllowed(Operation $parent, Instance $instance, bool $force): void
    {
        $plan = ValidatedData::object($parent->state['route_plan'] ?? []);
        $removed = $plan['removed_instance_ids'] ?? null;
        $previous = $plan['previous_target_instance_ids'] ?? null;
        $current = $plan['target_instance_ids'] ?? null;
        if (! $force || $parent->kind !== 'route.target_set' || $parent->target_type !== 'route'
            || ($plan['route_id'] ?? null) !== $parent->target_id
            || ($parent->state['route_active_stage'] ?? null) !== 'removal'
            || ! is_array($removed) || ! in_array($instance->id, $removed, true)
            || ! is_array($previous) || ! in_array($instance->id, $previous, true)
            || ! is_array($current) || in_array($instance->id, $current, true)
            || Route::query()->whereKey($parent->target_id)->value('project_id') !== $instance->project_id) {
            throw $this->forbidden();
        }
        $placements = $plan['placements'] ?? null;
        if (! is_array($placements)) {
            throw $this->forbidden();
        }
        $matching = array_values(array_filter($placements, static fn (mixed $placement): bool => is_array($placement)
            && ($placement['instance_id'] ?? null) === $instance->id));
        if (count($matching) !== 1 || ($matching[0]['node_id'] ?? null) !== $instance->node_id
            || ($matching[0]['production'] ?? null) !== $instance->placedOnAppProd()) {
            throw $this->forbidden();
        }
        $detached = $plan['detached_instances'] ?? null;
        if (! is_array($detached) || ($parent->input['detached_instances'] ?? null) !== $detached) {
            throw $this->forbidden();
        }
        $snapshot = array_values(array_filter($detached, static fn (mixed $placement): bool => is_array($placement)
            && ($placement['instance_id'] ?? null) === $instance->id));
        if (count($snapshot) !== 1 || count($snapshot[0]) !== 3 || ($snapshot[0]['node_id'] ?? null) !== $instance->node_id
            || ($snapshot[0]['checkout_path'] ?? null) !== $instance->checkout_path) {
            throw $this->forbidden();
        }
        $completed = ValidatedData::object($parent->state['route_completed_stages'] ?? []);
        $runs = ValidatedData::object($parent->state['executor_runs'] ?? []);
        $steps = ValidatedData::object($parent->state['steps'] ?? []);
        foreach (['database-committed', 'environment-synchronized', 'target-live-certificates', 'target-live-caddy',
            'router-published', 'vacated-published', 'target-staging-cleanup'] as $phase) {
            $run = $runs['stage-commit.'.$phase] ?? null;
            if (($completed[$phase] ?? null) !== true || ! is_array($run)
                || ($run['transport'] ?? null) !== 'gateway' || ($run['name'] ?? null) !== 'route.parent'
                || ($run['input'] ?? null) !== ['action' => 'commit', 'stage' => $phase]
                || ($run['step'] ?? null) !== 'stage.'.$phase || ($run['result'] ?? null) !== ['ready' => true, 'stage' => $phase]
                || ($steps[$run['step']] ?? null) !== $run['result']
                || ! $parent->steps()->where('name', $run['step'])->where('status', 'succeeded')->exists()) {
                throw $this->forbidden();
            }
        }
        $stages = ValidatedData::object($plan['stages'] ?? []);
        foreach (['target-live-caddy', 'router-published', 'vacated-published'] as $phase) {
            $intents = $stages[$phase] ?? [];
            if (! is_array($intents) || ! array_is_list($intents)) {
                throw $this->forbidden();
            }
            foreach ($intents as $intent) {
                if (! is_array($intent) || ($intent['kind'] ?? null) !== 'route.caddy_converge' || ! is_string($intent['key'] ?? null)) {
                    throw $this->forbidden();
                }
                $child = app(OperationChildren::class)->requireSucceeded($parent, $intent['key']);
                if ($child->kind !== 'route.caddy_converge' || $child->node_id !== ($intent['node_id'] ?? null)
                    || ($child->result['ready'] ?? null) !== true) {
                    throw $this->forbidden();
                }
            }
        }
    }

    public function parent(Operation $operation): ?Operation
    {
        if ($operation->parent_operation_id === null) {
            return null;
        }
        $parent = Operation::query()->findOrFail($operation->parent_operation_id);
        if ($parent->kind !== 'route.target_set') {
            return null;
        }
        app(OperationChildren::class)->verify($parent, $operation);
        if (! in_array($operation->kind, ['instance.destroy', 'instance.destroy_development'], true) || $operation->target_type !== 'instance'
            || $operation->child_key !== 'removal.'.$operation->target_id) {
            throw $this->forbidden();
        }

        return $parent;
    }

    private function forbidden(): ResourceOperationException
    {
        return new ResourceOperationException('operation.child_forbidden',
            'The Route parent has not proven this detached Instance removal.', 422);
    }
}
