<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\TaskVms\WakeTaskVmAction;
use App\Domain\Fleet\FleetReboot;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Task;

/**
 * The gate of an admission that needs the task VM of a group. A parked VM is admitted to its resume, and the admission refuses
 * until it runs, so the call never starts a run against a stopped machine. An ended group, a group with no VM and a group that
 * another operation holds pass through: the admission refuses them with its own code, and the holder resumes the VM itself when it
 * needs to. Call it before any transaction: the refusal must not roll back the resume it just queued.
 */
final class TaskVmAwake
{
    public static function require(Task $group): void
    {
        if (in_array($group->status, TaskGroupStatus::endedStatuses(), true) || self::held($group)) {
            return;
        }
        // A fleet reboot pass drains this host: work that started now would be cut by its reboot (ADR 0253).
        FleetReboot::refuseTaskWork($group);
        // The resume is its own Operation: it must not take the Idempotency-Key of the turn, check, publish or sync that asked for it.
        if (! app(OperationIdempotency::class)->suspended(static fn (): bool => app(WakeTaskVmAction::class)->forGroup($group))) {
            throw new ResourceOperationException('tasks.vm_resuming', 'The task VM was parked. Its resume is admitted; repeat the call when the VM runs.', 409);
        }
    }

    private static function held(Task $group): bool
    {
        return Operation::query()->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id)
            ->whereIn('kind', array_keys(app(DirectOperation::class)->definitions()))
            ->whereNotIn('status', OperationStatus::terminalValues())->exists();
    }
}
