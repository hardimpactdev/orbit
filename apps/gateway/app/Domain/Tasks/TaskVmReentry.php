<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\OperationStatus;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Jobs\TaskVms\ReleaseTaskVmSlot;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;

/** A published or idle-released task holds no workspace; later work starts from the base and its published branch, or its idle save. */
final readonly class TaskVmReentry
{
    /** @return array<string,mixed>|null */
    public static function capture(Task $group, string $commit): ?array
    {
        $vm = TaskVm::forGroup($group);
        if ($vm?->state === TaskVmState::Releasing) {
            throw new TaskCapacityException(TaskWait::Capacity, 'The previous task VM is still being deleted.');
        }
        if ($vm !== null) {
            return null;
        }

        // A group whose idle VM saved its workspace starts from that save while it holds the task branch head.
        return app(TaskProvisionIntent::class)->capture($group, app(TaskIdleSave::class)->restore($group, $commit));
    }

    /** @param array<string,mixed> $native */
    public static function record(Operation $operation, array $native): void
    {
        if (! is_array($operation->input['provision'] ?? null)) {
            return;
        }
        $view = $operation->replicate();
        $view->id = $operation->id;
        $view->input = ValidatedData::object($operation->input['provision']);
        $claimed = NativeRunResult::stepStatus($native, 'Claim a task place') === 'succeeded';
        app(TaskProvisionProjection::class)->record($view, $claimed ? 'succeeded' : 'failed', $claimed ? null : 'tasks.provision_failed', static fn (): Operation => $operation);
    }

    public static function refused(Operation $operation): void
    {
        if (! is_array($operation->input['provision'] ?? null)) {
            return;
        }
        $view = $operation->replicate();
        $view->id = $operation->id;
        $view->input = ValidatedData::object($operation->input['provision']);
        app(TaskProvisionProjection::class)->refused($view);
    }

    public static function release(Operation $operation): void
    {
        if ($operation->status !== OperationStatus::Succeeded) {
            return;
        }
        $group = Task::topLevel()->find($operation->target_id);
        $vm = $group instanceof Task ? TaskVm::forGroup($group) : null;
        if ($vm instanceof TaskVm) {
            $vm->update(['state' => TaskVmState::Releasing]);
            ReleaseTaskVmSlot::dispatch($vm->id, $group->id)->afterCommit();
            $group->update(['status' => TaskGroupStatus::WaitingForReview, 'settled_at' => now()]);
        }
    }
}
