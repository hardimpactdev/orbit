<?php

declare(strict_types=1);

namespace App\Data\Schedules;

use App\Domain\Schedules\ScheduleTargetType;
use App\Domain\Schedules\ScheduleTrigger;
use App\Infrastructure\Schedules\ContainerScheduleInspection;
use App\Models\Instance;
use App\Models\Schedule;
use App\Support\ValidatedData;
use DateTimeInterface;
use SensitiveParameter;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

#[MapOutputName(SnakeCaseMapper::class)]
final class ScheduleData extends Data
{
    public function __construct(
        public string $id,
        public string $targetType,
        public int $targetId,
        public string $name,
        public string $calendar,
        #[SensitiveParameter]
        public string|Optional $command,
        public int $timeoutSeconds,
        public string $desiredTimerState,
        public string $status,
        public ?string $failedStep,
        public ?string $errorCode,
        public ?string $lastRunAt,
        public ?string $lastRunStatus,
        public string|Optional $timerState,
    ) {}

    public static function fromModel(#[SensitiveParameter] Schedule $schedule, bool $includeCommand = true, bool $observe = false): self
    {
        $observed = $observe && $schedule->trigger === ScheduleTrigger::Container
            ? app(ContainerScheduleInspection::class)->observe($schedule) : [];

        return new self(
            id: $schedule->id,
            targetType: Instance::isMorphType($schedule->target_type)
                ? ScheduleTargetType::Instance->value
                : ScheduleTargetType::Node->value,
            targetId: $schedule->target_id,
            name: $schedule->name,
            calendar: $schedule->calendar,
            command: $includeCommand ? $schedule->command : Optional::create(),
            timeoutSeconds: $schedule->timeout_seconds,
            desiredTimerState: $schedule->desired_timer_state->value,
            status: $schedule->status->value,
            failedStep: $schedule->failed_step,
            errorCode: $schedule->error_code,
            lastRunAt: ValidatedData::nullableString($observed['last_run_at'] ?? $schedule->last_run_at?->format(DateTimeInterface::ATOM)),
            lastRunStatus: ValidatedData::nullableString($observed['last_run_status'] ?? $schedule->last_run_status?->value),
            timerState: isset($observed['state']) ? ValidatedData::string($observed['state']) : Optional::create(),
        );
    }
}
