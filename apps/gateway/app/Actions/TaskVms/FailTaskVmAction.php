<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\TaskVms\TaskVmState;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;

/**
 * Fails a task VM row with its error, and asks its tick group for assistance with `$reason`, so the failure shows in
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
        return DB::transaction(function () use ($vm, $from, $code, $message): bool {
            $failed = TaskVm::query()->whereKey($vm->id)->whereIn('state', $from)
                ->update(['state' => TaskVmState::Failed, 'error_code' => $code, 'error_message' => $message]) === 1;
            if ($failed) {
                $vm->refresh();
            }

            return $failed;
        });
    }

    /** Asks once for a failed VM, and notifies only after the request commits. */
}
