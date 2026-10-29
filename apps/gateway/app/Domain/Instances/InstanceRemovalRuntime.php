<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Schedule;
use App\Support\ValidatedData;

/**
 * Binds per-record runtime removal to the exact successful Gateway inventory receipt.
 *
 * @phpstan-import-type ChildIntent from OperationFamilyChildren
 */
final readonly class InstanceRemovalRuntime
{
    /** @return list<ChildIntent> */
    public function children(Operation $parent, int $instanceId): array
    {
        $intent = app(RemovalLifecycleExecution::class)->intent($parent);
        $member = array_find(InstanceLifecycleInput::members($intent), static fn (array $member): bool => $member['instance_id'] === $instanceId);
        if ($parent->kind !== 'instance.destroy' || $parent->target_type !== 'instance'
            || $parent->target_id !== ($intent['instance_id'] ?? null) || $member === null
            || $parent->node_id !== $member['node_id']) {
            throw new ResourceOperationException('operation.child_forbidden', 'Runtime removal requires the recorded Instance placement.', 422);
        }
        $receipt = app(InstanceLifecycleExecution::class)->proven($parent, "removal-{$instanceId}-runtime-intent");
        if (! array_key_exists('process_ids', $receipt) || ! array_key_exists('schedule_ids', $receipt)) {
            throw new ResourceOperationException('operation.completion_unproven', 'A complete runtime inventory receipt is required.', 409);
        }
        $children = [];
        foreach (ValidatedData::integerList($receipt['process_ids']) as $id) {
            $children[] = ['key' => "remove-process-{$instanceId}-{$id}", 'kind' => 'process.destroy',
                'node_id' => $parent->node_id, 'input' => ['process_id' => $id]];
        }
        foreach (ValidatedData::stringList($receipt['schedule_ids']) as $id) {
            $children[] = ['key' => "remove-schedule-{$instanceId}-{$id}", 'kind' => 'schedule.destroy',
                'node_id' => $parent->node_id, 'input' => ['id' => $id]];
        }

        return $children;
    }

    /** @param ChildIntent $intent */
    public function owner(Operation $parent, array $intent): int
    {
        foreach (InstanceLifecycleInput::members(app(RemovalLifecycleExecution::class)->intent($parent)) as $member) {
            $id = ValidatedData::integer($member['instance_id']);
            if (! app(InstanceLifecycleChildren::class)->ready($parent, "removal-{$id}-runtime-intent")) {
                continue;
            }
            foreach ($this->children($parent, $id) as $child) {
                if (OperationChildIntent::same($intent, $child)) {
                    return $id;
                }
            }
        }

        throw new ResourceOperationException('operation.child_forbidden', 'The runtime record is outside the proven removal inventory.', 422);
    }

    public function succeeded(Operation $parent, int $instanceId): bool
    {
        foreach ($this->children($parent, $instanceId) as $intent) {
            if (! app(InstanceLifecycleChildren::class)->succeeded($parent, $intent['key'])) {
                return false;
            }
            app(InstanceLifecycleExecution::class)->assertChild($parent, $intent['key'], $intent['kind']);
        }

        return true;
    }

    public function assertRemoved(Operation $parent, int $instanceId): void
    {
        if (! $this->succeeded($parent, $instanceId)
            || Process::query()->whereIn('owner_type', Instance::morphTypes())->where('owner_id', $instanceId)->exists()
            || Schedule::query()->whereIn('target_type', Instance::morphTypes())->where('target_id', $instanceId)->exists()) {
            throw new ResourceOperationException('operation.child_unproven', 'Every recorded runtime child must finish removal first.', 409);
        }
    }
}
