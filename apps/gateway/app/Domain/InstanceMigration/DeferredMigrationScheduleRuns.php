<?php

declare(strict_types=1);

namespace App\Domain\InstanceMigration;

use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Schedules\ScheduleOperationAdmission;
use App\Domain\Schedules\ScheduleOperationCompletion;
use App\Domain\Schedules\ScheduleTrigger;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Schedule;
use App\Support\ValidatedData;

/** A persistent timer's fire is recorded while migration owns admission, then admitted against the final runtime. */
final readonly class DeferredMigrationScheduleRuns
{
    public function __construct(private OperationStore $store, private OperationIdempotency $idempotency) {}

    public static function waiting(Operation $operation): bool
    {
        return $operation->kind === 'schedule.run' && $operation->status === OperationStatus::Waiting
            && is_int($operation->input['deferred_instance_migration'] ?? null);
    }

    /** Called under the Instance lock shared with migration admission and terminal projection. */
    public function admit(Schedule $schedule, ?Node $caller, ?string $requestId): ?Operation
    {
        if ($schedule->trigger !== ScheduleTrigger::Node) {
            return null;
        }
        $migration = Operation::query()->where('kind', InstanceMigration::Kind)->where('target_type', 'instance')
            ->where('target_id', $schedule->target_id)->whereNotIn('status', OperationStatus::terminalValues())->first();
        if ($migration === null) {
            return null;
        }
        if (($existing = $this->idempotency->replay('schedule.run')) !== null) {
            return $existing;
        }
        $operation = $this->store->create('schedule.run', 'instance_schedules', $schedule->target_id, $schedule->host_node_id,
            ['id' => $schedule->id, 'caller_node_id' => $caller?->id, 'deferred_instance_migration' => $migration->id], 'operator', $requestId);
        $this->store->change($operation, ['status' => OperationStatus::Waiting]);

        return $operation;
    }

    /** Migration is terminal and the Instance lock is held; queue only after that transaction commits. */
    public function release(Operation $migration): void
    {
        foreach (Operation::query()->where('kind', 'schedule.run')->where('status', OperationStatus::Waiting)
            ->where('target_type', 'instance_schedules')->where('target_id', $migration->target_id)
            ->where('input->deferred_instance_migration', $migration->id)->orderBy('id')->lockForUpdate()->get() as $operation) {
            try {
                $schedule = Schedule::query()->whereKey($operation->input['id'])->first();
                if ($schedule === null) {
                    throw new ResourceOperationException('schedule.target_unavailable', 'The deferred Schedule no longer exists.', 409);
                }
                $input = app(ScheduleOperationAdmission::class)->inputFor($schedule, 'run',
                    caller: ($operation->input['caller_node_id'] ?? null) === null ? null : Node::query()->find(ValidatedData::integer($operation->input['caller_node_id'])));
                $this->store->change($operation, ['input' => $input, 'status' => OperationStatus::Queued, 'started_at' => now()]);
                app(SwampDispatch::class)->start($operation);
            } catch (ResourceOperationException $error) {
                $this->store->change($operation, ['status' => OperationStatus::Failed, 'finished_at' => now(),
                    'error_code' => $error->errorCode, 'result' => ['error' => ['code' => $error->errorCode, 'message' => $error->getMessage()]]]);
                app(ScheduleOperationCompletion::class)->complete($operation, OperationOutcome::failed($error->errorCode));
            }
        }
    }
}
