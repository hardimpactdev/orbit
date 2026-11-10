<?php

declare(strict_types=1);

namespace App\Domain\Logs;

use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Schedules\ScheduleSpecification;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Schedule;
use SensitiveParameter;

/**
 * Resolves the log source of an Instance, a Process or a Schedule from the Gateway's own records: the same file,
 * unit, or container that the one-shot SSH read uses (ADR 0153).
 */
final readonly class LogStreamTargetResolver
{
    public function __construct(
        private ProcessTargetResolver $processes,
        private SystemdProcessRenderer $systemd,
        private DockerProcessRenderer $docker,
        private ScheduleTargetResolver $schedules,
        private ScheduleSpecification $scheduleUnits,
    ) {}

    public function forInstance(Instance $instance): LogStreamTarget
    {
        $checkout = StoragePath::tryParse($instance->source_is_laravel === true ? $instance->applicationDirectory() : $instance->checkout_path);

        if ($checkout === null) {
            throw new ResourceOperationException(
                errorCode: 'instance.checkout_path_invalid',
                message: "Instance [{$instance->name}] has an invalid checkout path.",
            );
        }

        return new LogStreamTarget(
            LogStreamRecordType::Instance,
            (int) $instance->id,
            Node::query()->findOrFail($instance->node_id),
            LogStreamSource::laravel($checkout),
            // A production Instance lives in its own home, which the agent's unit hides (ADR 0151).
            sshOnly: $instance->placedOnAppProd(),
        );
    }

    public function forSchedule(#[SensitiveParameter] Schedule $schedule): LogStreamTarget
    {
        if ($schedule->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException('schedule.state_invalid', 'The Schedule logs are unavailable in its current state.', 409);
        }

        return new LogStreamTarget(LogStreamRecordType::Schedule, $schedule->id,
            $this->schedules->placementOf($schedule)->node,
            LogStreamSource::journal($this->scheduleUnits->serviceName($schedule)));
    }

    public function forProcess(#[SensitiveParameter] Process $process): LogStreamTarget
    {
        $target = $this->processes->forInspection($process);
        if ($target->container !== null) {
            // The Node agent follows a journal on its Node; a container's journal is out of its reach.
            throw new ResourceOperationException('process.target_unsupported', 'A Process in an Instance container has no live log stream yet; read its logs without --follow.', 409);
        }
        $node = $target->node;
        $source = match ($process->runtime) {
            ProcessRuntime::Systemd => LogStreamSource::journal($this->systemd->unitName($process)),
            ProcessRuntime::Docker => LogStreamSource::docker($this->docker->containerName($process), (int) $process->id),
        };

        return new LogStreamTarget(LogStreamRecordType::Process, (int) $process->id, $node, $source);
    }
}
