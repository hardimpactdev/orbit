<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Schedule;

/** Installs an already captured, disabled Schedule on its admitted Instance. */
final readonly class ScheduleRuntimeChildAdmission implements OperationChildAdmission
{
    public function __construct(private ScheduleOperationAdmission $admission) {}

    public function admit(Operation $parent, array $intent): Operation
    {
        $id = $intent['input']['id'] ?? null;
        if (! is_string($id) || ! OperationChildIntent::same($intent['input'], ['id' => $id])
            || $parent->kind !== 'schedule.install_instance_definitions' || $intent['kind'] !== 'schedule.create'
            || $parent->target_type !== 'instance' || $intent['node_id'] !== $parent->node_id) {
            $this->refuse();
        }
        $schedule = Schedule::query()->whereKey($id)->firstOrFail();
        if (! Instance::isMorphType($schedule->target_type) || $schedule->target_id !== $parent->target_id
            || $schedule->host_node_id !== $parent->node_id || $schedule->source_definition_id === null
            || $schedule->desired_timer_state !== DesiredTimerState::Disabled
            || in_array($schedule->status, [LifecycleStatus::Active, LifecycleStatus::Removing], true)) {
            $this->refuse();
        }

        return $this->admission->execute($schedule, 'create');
    }

    private function refuse(): never
    {
        throw new ResourceOperationException('operation.child_forbidden', 'This Schedule is not an eligible stopped definition copy.', 422);
    }
}
