<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Domain\Operations\OperationFamilyExecution;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Schedule;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Exact descriptors let a receipt read precede the first remote invocation. */
final readonly class ScheduleOperationExecution implements OperationFamilyExecution
{
    /** @return list<string> */
    public static function fields(string $verb): array
    {
        return match ($verb) {
            'create' => ['id', 'user', 'group', 'home', 'working_directory', 'shell', 'login_shell', 'command', 'calendar', 'timeout_seconds', 'desired'],
            'destroy' => ['id', 'group', 'cascade'],
            'enable', 'run' => ['id', 'user', 'working_directory'],
            default => throw new ResourceOperationException('operation.execution_forbidden', 'Unknown Schedule action.', 422),
        };
    }

    public function context(Operation $operation): array
    {
        $verb = substr($operation->kind, strlen('schedule.'));
        $command = match ($verb) {
            'create' => 'install', 'destroy' => 'remove', 'enable' => 'activate', 'run' => 'run',
            default => throw new ResourceOperationException('operation.execution_forbidden', 'Unknown Schedule action.', 422),
        };
        $key = 'schedule-'.$command;

        return ['descriptors' => [['key' => $key, 'step' => $key, 'transport' => 'agent', 'name' => 'schedule.'.$command,
            'input' => Arr::only($operation->input, self::fields($verb)), 'resumable' => $verb !== 'run', 'required' => true]]];
    }

    public function input(Operation $operation, array $run): array
    {
        return $this->context($operation)['descriptors'][0]['input'];
    }

    public function beforeRun(Operation $operation, array $run): void
    {
        $schedule = Schedule::query()->whereKey(ValidatedData::string($operation->input['id']))->firstOrFail();
        $type = Instance::isMorphType($schedule->target_type) ? 'instance_schedules'
            : ($schedule->target_type === Node::class ? 'node_schedules' : null);
        if ($operation->target_type !== $type || $operation->target_id !== $schedule->target_id || $operation->node_id !== $schedule->host_node_id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Schedule placement changed after admission.', 409);
        }
        $target = match ($operation->kind) {
            'schedule.create' => app(ScheduleTargetResolver::class)->forInstallation($schedule),
            'schedule.destroy' => app(ScheduleTargetResolver::class)->forRemoval($schedule),
            default => app(ScheduleTargetResolver::class)->forSchedule($schedule),
        };
        if ($target->node->id !== $operation->node_id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Schedule Node changed after admission.', 409);
        }
    }

    public function received(Operation $operation, array $run, array $receipt): array
    {
        return $receipt;
    }

    public function assertCompletion(Operation $operation, OperationOutcome $outcome): void {}

    public function cancellationBoundary(Operation $operation): ?array
    {
        return null;
    }
}
