<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Schedule;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class ScheduleOperationAdmission
{
    public function __construct(private StartOperationAction $operations, private ScheduleTargetResolver $targets, private ScheduleSpecification $specification) {}

    public function execute(Schedule $schedule, string $verb, bool $cascade = false, ?Node $caller = null, ?string $requestId = null, bool $created = false): Operation
    {
        return DB::transaction(function () use ($schedule, $verb, $cascade, $caller, $requestId, $created): Operation {
            $input = $this->inputFor($schedule, $verb, $cascade, $caller, $created);
            // UUID Schedules share their owner's target lock; Operation target IDs are integers.
            $owner = Instance::isMorphType($schedule->target_type) ? 'instance_schedules' : 'node_schedules';
            $nodeId = ValidatedData::integer($input['node_id']);
            $operation = $this->operations->execute('schedule.'.$verb, $owner, $schedule->target_id, $nodeId, $input, 'operator', $requestId);
            if (in_array($verb, ['create', 'destroy'], true)) {
                $schedule->update(['status' => $verb === 'destroy' ? LifecycleStatus::Removing : LifecycleStatus::Provisioning, 'failed_step' => null, 'error_code' => null]);
            }

            return $operation;
        });
    }

    /** @return array<string, mixed> */
    public function inputFor(Schedule $schedule, string $verb, bool $cascade = false, ?Node $caller = null, bool $created = false): array
    {
        $target = match ($verb) {
            'create' => $this->targets->forInstallation($schedule),
            'destroy' => $this->targets->forRemoval($schedule),
            // A run reads the account on the Node itself (task-command-run); only Node timer units need it here.
            'run' => $this->targets->placementOf($schedule),
            default => $this->targets->forSchedule($schedule),
        };
        $input = ['id' => $schedule->id, 'node_id' => $target->node->id, 'caller_node_id' => $caller?->id, 'created' => $created];
        $input += $this->specification->input($schedule, $target);
        $input['command_hash'] = hash('sha256', $schedule->command);
        $input['command'] = ['$secret' => 'schedule.command', 'id' => $schedule->target_id];
        if ($verb === 'destroy') {
            $input['cascade'] = $cascade;
        }

        return $input;
    }
}
