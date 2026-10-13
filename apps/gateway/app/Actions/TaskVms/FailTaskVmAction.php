<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\CoderSettleNotifier;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fails a task VM row with its error, and asks the row's group for assistance with `$reason`, so the failure shows in
 * `tasks:status`. Only a row that is still in one of `$from` fails, so a destroy, a claim, or a resume that came first
 * wins. A pool row has no group to ask.
 */
final readonly class FailTaskVmAction
{
    public function __construct(private CoderSettleNotifier $coder) {}

    /**
     * @param  list<TaskVmState>  $from
     * @return bool whether this call failed the row
     */
    public function execute(TaskVm $vm, array $from, string $code, string $message, string $reason): bool
    {
        return DB::transaction(function () use ($vm, $from, $code, $message, $reason): bool {
            $failed = TaskVm::query()->whereKey($vm->id)->whereIn('state', $from)
                ->update(['state' => TaskVmState::Failed, 'error_code' => $code, 'error_message' => $message]) === 1;
            if ($failed) {
                $vm->refresh();
                $this->requestAssistance($vm, $reason);
            }

            return $failed;
        });
    }

    /** Asks once for a failed VM, and notifies only after the request commits. */
    public function requestAssistance(TaskVm $vm, string $reason): void
    {
        if ($vm->group_id === null) {
            return;
        }
        DB::transaction(function () use ($vm, $reason): void {
            $group = Task::topLevel()->lockForUpdate()->find($vm->group_id);
            if (! $group instanceof Task) {
                return;
            }
            if ($vm->state === TaskVmState::Failed && $group->capacity_wait_reason !== null) {
                $group->update(['capacity_wait_reason' => null]);
            }
            if ($group->assistance_requested && $group->assistance_reason === $reason) {
                return;
            }
            $wasAsking = $group->assistance_requested;
            $applied = TaskAssistance::apply($group, AssistanceKind::Failure, null, $reason, replaceFailure: true);
            if (! $wasAsking && $applied && ! in_array($group->status, [TaskGroupStatus::Completed, TaskGroupStatus::Cancelled], true)) {
                Log::warning('A task group asks for assistance: a task VM operation failed.', ['group_id' => $group->id, 'reason' => $reason]);
                DB::afterCommit(fn () => $this->coder->assistance($group, $reason));
            }
        });
    }
}
