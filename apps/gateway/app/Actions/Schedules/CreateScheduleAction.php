<?php

declare(strict_types=1);

namespace App\Actions\Schedules;

use App\Data\Schedules\AddScheduleData;
use App\Domain\Operations\AwaitOperation;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Schedules\ScheduleErrorCode;
use App\Domain\Schedules\ScheduleOperationAdmission;
use App\Domain\Schedules\ScheduleSpecificationValidator;
use App\Domain\Schedules\ScheduleTarget;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Domain\Schedules\ScheduleTargetType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Schedule;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

final readonly class CreateScheduleAction
{
    public function __construct(
        private ScheduleSpecificationValidator $validator,
        private ScheduleTargetResolver $targets,
        private ProcessAdmissionLock $admissions,
    ) {}

    /** @return array{schedule: Schedule, created: bool} */
    public function execute(#[SensitiveParameter] AddScheduleData $data): array
    {
        $operation = $this->admit($data);
        app(AwaitOperation::class)->execute($operation);

        return ['schedule' => Schedule::query()->whereKey($operation->input['id'])->firstOrFail(), 'created' => $operation->input['created'] === true];
    }

    public function admit(AddScheduleData $data, ?Node $caller = null, ?string $requestId = null): Operation
    {
        $this->validator->validate($data);
        $this->targets->resolve($data->targetType, $data->targetId);
        $ownerIds = $data->targetType === ScheduleTargetType::Instance ? [$data->targetId] : [];

        try {
            return $this->admissions->run($ownerIds, fn (): Operation => DB::transaction(fn (): Operation => $this->admitOwned($data, $caller, $requestId)));
        } catch (ResourceOperationException $exception) {
            if ($exception->errorCode !== 'process.operation_busy') {
                throw $exception;
            }
            throw new ResourceOperationException(ScheduleErrorCode::StateInvalid->value, 'Another target operation is active. Retry the Schedule request.', 409, $exception);
        }
    }

    private function admitOwned(AddScheduleData $data, ?Node $caller, ?string $requestId): Operation
    {
        $target = DB::transaction(function () use ($data): ScheduleTarget {
            $model = match ($data->targetType) {
                ScheduleTargetType::Node => Node::query()->lockForUpdate()->findOrFail($data->targetId),
                ScheduleTargetType::Instance => Instance::query()->lockForUpdate()->findOrFail($data->targetId),
            };

            return $this->targets->resolve($data->targetType, $model->id);
        });

        $admission = DB::transaction(function () use ($data, $target): array {
            $schedule = Schedule::query()
                ->whereIn('target_type', $data->targetType->storedTypes())
                ->where('target_id', $data->targetId)
                ->where('name', $data->name)
                ->first() ?? new Schedule([
                    'target_type' => $data->targetType->storedType(),
                    'target_id' => $data->targetId,
                    'name' => $data->name,
                ]);
            $created = ! $schedule->exists;

            if ($schedule->exists && ! $this->matches($schedule, $data)) {
                throw new ResourceOperationException(
                    ScheduleErrorCode::RetryConflict->value,
                    'A Schedule with this target and name has a different specification.',
                    409,
                );
            }

            if ($schedule->exists && $schedule->host_node_id !== $target->node->id) {
                throw new ResourceOperationException(
                    ScheduleErrorCode::TargetInUse->value,
                    'The Schedule target placement changed while the Schedule exists.',
                    409,
                );
            }

            if ($schedule->exists && $schedule->status === LifecycleStatus::Removing) {
                throw new ResourceOperationException(
                    ScheduleErrorCode::StateInvalid->value,
                    'The Schedule is being removed.',
                    409,
                );
            }

            $desired = $created
                ? ($data->start ? DesiredTimerState::Enabled : DesiredTimerState::Disabled)
                : $schedule->desired_timer_state;
            $schedule->fill([
                'host_node_id' => $target->node->id,
                'calendar' => $data->calendar,
                'command' => $data->command,
                'timeout_seconds' => $data->timeoutSeconds,
                'desired_timer_state' => $desired,
                'status' => LifecycleStatus::Provisioning,
                'failed_step' => null,
                'error_code' => null,
            ])->save();

            return ['schedule' => $schedule, 'created' => $created];
        });

        return app(ScheduleOperationAdmission::class)->execute($admission['schedule'], 'create', caller: $caller, requestId: $requestId, created: $admission['created']);
    }

    private function matches(Schedule $schedule, #[SensitiveParameter] AddScheduleData $data): bool
    {
        return
            in_array($schedule->target_type, $data->targetType->storedTypes(), true)
            && $schedule->target_id === $data->targetId
            && $schedule->name === $data->name
            && hash_equals($schedule->calendar, $data->calendar)
            && hash_equals($schedule->command, $data->command)
            && $schedule->timeout_seconds === $data->timeoutSeconds;
    }
}
