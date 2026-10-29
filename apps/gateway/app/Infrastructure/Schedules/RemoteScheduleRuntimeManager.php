<?php

declare(strict_types=1);

namespace App\Infrastructure\Schedules;

use App\Data\Schedules\ScheduleLogsData;
use App\Domain\Operations\AwaitOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Schedules\ScheduleErrorCode;
use App\Domain\Schedules\ScheduleOperationAdmission;
use App\Domain\Schedules\ScheduleOperationException;
use App\Domain\Schedules\ScheduleRuntimeManager;
use App\Domain\Schedules\ScheduleTarget;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Shared\StoredValue;
use App\Models\Schedule;
use SensitiveParameter;
use Throwable;

/**
 * A Schedule's systemd units on its Node, through the agent's `schedule.*` commands
 * (docs/reference/processes-and-schedules.md).
 */
final readonly class RemoteScheduleRuntimeManager implements ScheduleRuntimeManager
{
    private const int MAX_LOG_BYTES = 1_048_576;

    public function __construct(
        private ScheduleTargetResolver $targets,
        private AgentCommandRunner $agents,
    ) {}

    public function install(#[SensitiveParameter] Schedule $schedule): void
    {
        $this->await($schedule, 'create');
    }

    public function activate(#[SensitiveParameter] Schedule $schedule): void
    {
        $this->await($schedule, 'enable');
    }

    public function run(#[SensitiveParameter] Schedule $schedule): void
    {
        $this->await($schedule, 'run');
    }

    public function logs(#[SensitiveParameter] Schedule $schedule, int $lines): ScheduleLogsData
    {
        if ($lines < 1 || $lines > 1000) {
            throw $this->failure('logs-validation', ScheduleErrorCode::LogsFailed, 'The Schedule log limit is invalid.');
        }

        $target = $this->targets->forSchedule($schedule);
        $logs = $this->call($target, 'schedule.logs', ['id' => $schedule->id, 'lines' => $lines, 'max_bytes' => self::MAX_LOG_BYTES], 'logs', ScheduleErrorCode::LogsFailed, 'Schedule logs could not be read.');

        return new ScheduleLogsData(StoredValue::string($logs['output'] ?? null), ($logs['truncated'] ?? false) === true);
    }

    public function remove(#[SensitiveParameter] Schedule $schedule, bool $cascade): bool
    {
        $this->await($schedule, 'destroy', $cascade);

        return ! Schedule::query()->whereKey($schedule->id)->exists();
    }

    private function await(Schedule $schedule, string $verb, bool $cascade = false): void
    {
        $operation = app(ScheduleOperationAdmission::class)->execute($schedule, $verb, $cascade);
        $result = app(AwaitOperation::class)->execute($operation);
        if ($result->status !== OperationStatus::Succeeded) {
            throw new ResourceOperationException($result->error_code ?? 'schedule.operation_failed', 'The Schedule Operation did not succeed.', 409);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function call(ScheduleTarget $target, string $command, array $input, string $step, ScheduleErrorCode $error, string $message): array
    {
        $host = $target->node->wireguard_ip;

        if (! is_string($host) || $host === '') {
            throw $this->failure('connect', ScheduleErrorCode::NodeUnreachable, 'The Schedule host Node is unreachable.');
        }

        try {
            return $this->agents->run($target->node, $command, $input);
        } catch (AgentCommandRefused $refusal) {
            throw match ($refusal->errorCode) {
                'schedule.target_unavailable' => $this->failure('resolve-release', ScheduleErrorCode::TargetUnavailable, 'The Schedule target is unavailable.', $refusal),
                'schedule.artifact_conflict' => $this->failure('inspect-artifacts', ScheduleErrorCode::ArtifactConflict, 'Schedule artifacts conflict.', $refusal),
                'schedule.calendar_invalid' => $this->failure('validate-calendar', ScheduleErrorCode::CalendarInvalid, 'The Schedule calendar is invalid.', $refusal),
                'schedule.rollback_failed' => $step === 'install'
                    ? $this->failure('rollback', ScheduleErrorCode::RollbackFailed, 'Schedule installation rollback failed.', $refusal)
                    : $this->failure("{$step}-rollback", ScheduleErrorCode::RollbackFailed, 'Schedule activation rollback failed.', $refusal),
                default => $this->failure($step, $error, $message, $refusal),
            };
        } catch (Throwable $exception) {
            throw $this->failure('connect', ScheduleErrorCode::NodeUnreachable, 'The Schedule host Node is unreachable.', $exception);
        }
    }

    private function failure(
        string $step,
        ScheduleErrorCode $error,
        string $message,
        ?Throwable $previous = null,
    ): ScheduleOperationException {
        return new ScheduleOperationException($step, $error, $message, previous: $previous);
    }
}
