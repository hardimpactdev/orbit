<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
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
        if (! array_key_exists('schedule.'.$verb, OperationKinds::definitions())) {
            throw new ResourceOperationException('operation.kind_unavailable', 'This Schedule kind requires the shared receipt contract.', 409);
        }

        return DB::transaction(function () use ($schedule, $verb, $cascade, $caller, $requestId, $created): Operation {
            $input = $this->inputFor($schedule, $verb, $cascade, $caller, $created);
            // UUID Schedules share their owner's target lock; Operation target IDs are integers.
            $owner = Instance::isMorphType($schedule->target_type) ? 'instance_schedules' : 'node_schedules';
            $operation = $this->operations->execute('schedule.'.$verb, $owner, $schedule->target_id, ValidatedData::integer($input['node_id']), $input, 'operator', $requestId);
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
            default => $this->targets->forSchedule($schedule),
        };
        $input = ['id' => $schedule->id, 'node_id' => $target->node->id, 'caller_node_id' => $caller?->id, 'created' => $created];
        if ($verb === 'create') {
            $input += $this->specification->input($schedule, $target);
            $input['command'] = ['$secret' => 'schedule.command', 'id' => $schedule->target_id];
            $input['command_hash'] = hash('sha256', $schedule->command);
        } elseif ($verb === 'destroy') {
            $input += ['group' => $target->group, 'cascade' => $cascade];
        } else {
            $input += ['user' => $target->user, 'working_directory' => $target->workingDirectory];
        }

        return $input;
    }
}
