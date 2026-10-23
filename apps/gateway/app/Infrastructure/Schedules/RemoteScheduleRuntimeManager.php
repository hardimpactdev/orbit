<?php

declare(strict_types=1);

namespace App\Infrastructure\Schedules;

use App\Data\Schedules\ScheduleLogsData;
use App\Domain\Schedules\ScheduleErrorCode;
use App\Domain\Schedules\ScheduleOperationException;
use App\Domain\Schedules\ScheduleRenderer;
use App\Domain\Schedules\ScheduleRuntimeManager;
use App\Domain\Schedules\ScheduleTarget;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Schedule;
use SensitiveParameter;
use Throwable;

/**
 * A Schedule's systemd units on its Node, through the agent's `schedules.*` commands
 * (docs/reference/processes-and-schedules.md).
 */
final readonly class RemoteScheduleRuntimeManager implements ScheduleRuntimeManager
{
    private const int MAX_LOG_BYTES = 1_048_576;

    public function __construct(
        private ScheduleTargetResolver $targets,
        private ScheduleRenderer $renderer,
        private AgentCommandRunner $agents,
    ) {}

    public function install(#[SensitiveParameter] Schedule $schedule): void
    {
        $target = $this->targets->forInstallation($schedule);

        try {
            $units = [
                'script' => $this->renderer->renderScript($schedule, $target),
                'service' => $this->renderer->renderService($schedule, $target),
                'timer' => $this->renderer->renderTimer($schedule),
            ];
        } catch (Throwable $exception) {
            throw $this->failure('render', ScheduleErrorCode::InstallFailed, 'Schedule installation failed.', $exception);
        }

        $this->call($target, 'schedules.install', [
            ...$this->runnable($schedule, $target),
            'group' => $target->group,
            'desired' => $schedule->desired_timer_state->value,
            'calendar' => $schedule->calendar,
            ...$units,
        ], 'install', ScheduleErrorCode::InstallFailed, 'Schedule installation failed.');
    }

    public function activate(#[SensitiveParameter] Schedule $schedule): void
    {
        $target = $this->targets->forSchedule($schedule);
        $this->call($target, 'schedules.activate', $this->runnable($schedule, $target), 'activate', ScheduleErrorCode::ActivationFailed, 'Schedule activation failed.');
    }

    public function run(#[SensitiveParameter] Schedule $schedule): void
    {
        $target = $this->targets->forSchedule($schedule);
        $this->call($target, 'schedules.run', $this->runnable($schedule, $target), 'run', ScheduleErrorCode::RunFailed, 'Schedule run failed.');
    }

    public function logs(#[SensitiveParameter] Schedule $schedule, int $lines): ScheduleLogsData
    {
        if ($lines < 1 || $lines > 1000) {
            throw $this->failure('logs-validation', ScheduleErrorCode::LogsFailed, 'The Schedule log limit is invalid.');
        }

        $target = $this->targets->forSchedule($schedule);
        $logs = $this->call($target, 'schedules.logs', ['id' => $schedule->id, 'lines' => $lines, 'max_bytes' => self::MAX_LOG_BYTES], 'logs', ScheduleErrorCode::LogsFailed, 'Schedule logs could not be read.');

        return new ScheduleLogsData((string) ($logs['output'] ?? ''), ($logs['truncated'] ?? false) === true);
    }

    public function remove(#[SensitiveParameter] Schedule $schedule, bool $cascade): bool
    {
        $target = $this->targets->forRemoval($schedule);

        try {
            $this->call($target, 'schedules.remove', ['id' => $schedule->id, 'group' => $target->group, 'cascade' => $cascade], 'remove', ScheduleErrorCode::RemoveFailed, 'Schedule removal failed.');
        } catch (ScheduleOperationException $exception) {
            if (! $cascade && $exception->getPrevious() instanceof AgentCommandRefused && $exception->getPrevious()->errorCode === 'schedules.run_active') {
                return false;
            }

            throw $exception;
        }

        return true;
    }

    /** @return array{id: string, user: string, working_directory: string} */
    private function runnable(Schedule $schedule, ScheduleTarget $target): array
    {
        return ['id' => $schedule->id, 'user' => $target->user, 'working_directory' => $target->workingDirectory];
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
                'schedules.target_unavailable' => $this->failure('resolve-release', ScheduleErrorCode::TargetUnavailable, 'The Schedule target is unavailable.', $refusal),
                'schedules.artifact_conflict' => $this->failure('inspect-artifacts', ScheduleErrorCode::ArtifactConflict, 'Schedule artifacts conflict.', $refusal),
                'schedules.calendar_invalid' => $this->failure('validate-calendar', ScheduleErrorCode::CalendarInvalid, 'The Schedule calendar is invalid.', $refusal),
                'schedules.rollback_failed' => $step === 'install'
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
