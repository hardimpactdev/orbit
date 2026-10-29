<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Domain\Instances\InstanceRemovalRuntime;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Schedule;
use Illuminate\Support\Facades\DB;

/** Installs an already captured, disabled Schedule on its admitted Instance. */
final readonly class ScheduleRuntimeChildAdmission implements OperationChildAdmission
{
    public function __construct(private ScheduleOperationAdmission $admission) {}

    public function admit(Operation $parent, array $intent): Operation
    {
        if ($parent->kind === 'instance.destroy') {
            return $this->remove($parent, $intent);
        }
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

    /** @param array{key:string,kind:string,node_id:int|null,input:array<string,mixed>} $intent */
    private function remove(Operation $parent, array $intent): Operation
    {
        $owner = app(InstanceRemovalRuntime::class)->owner($parent, $intent);

        return app(ProcessAdmissionLock::class)->run([$owner], function () use ($parent, $intent, $owner): Operation {
            return DB::transaction(function () use ($parent, $intent, $owner): Operation {
                $id = $intent['input']['id'] ?? null;
                if (! is_string($id) || $intent['kind'] !== 'schedule.destroy'
                    || ! OperationChildIntent::same($intent['input'], ['id' => $id])) {
                    $this->refuse();
                }
                $schedule = Schedule::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                if (! Instance::isMorphType($schedule->target_type) || $schedule->target_id !== $owner
                    || $schedule->host_node_id !== $parent->node_id
                    || Instance::query()->whereKey($owner)->value('node_id') !== $parent->node_id) {
                    $this->refuse();
                }

                return $this->admission->execute($schedule, 'destroy', cascade: true);
            });
        });
    }

    private function refuse(): never
    {
        throw new ResourceOperationException('operation.child_forbidden', 'This Schedule is not an eligible stopped definition copy.', 422);
    }
}
