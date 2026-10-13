<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Task;
use App\Models\TaskVm;

/**
 * Fails a task VM row with its error, and asks the row's group for assistance with `$reason`, so the failure shows in
 * `tasks:status`. Only a row that is still in one of `$from` fails, so a destroy, a claim, or a resume that came first
 * wins. A pool row has no group to ask.
 */
final readonly class FailTaskVmAction
{
    /**
     * @param  list<TaskVmState>  $from
     * @return bool whether this call failed the row
     */
    public function execute(TaskVm $vm, array $from, string $code, string $message, string $reason): bool
    {
        $failed = TaskVm::query()->whereKey($vm->id)->whereIn('state', $from)
            ->update(['state' => TaskVmState::Failed, 'error_code' => $code, 'error_message' => $message]) === 1;
        $group = $vm->group;
        if ($failed && $group instanceof Task) {
            TaskAssistance::apply($group, AssistanceKind::Failure, null, $reason, replaceFailure: true);
        }

        return $failed;
    }
}
