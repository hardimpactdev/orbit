<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskCancelIntent;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Admits the ending of a group whose provision ended cancelled (docs/cli/tasks/provision.mmd): the run may have stopped while it
 * created the VM or the workspace, and the ending destroys what it started. The admission is keyed per provision, so a retry
 * never admits a second ending; while another operation holds the group the job tries again, and a refused ending asks for help.
 */
final class EndCancelledTaskProvision implements ShouldQueue
{
    use Queueable;

    public int $tries = 12;

    public int $backoff = 10;

    public function __construct(public int $groupId, public int $provisionId)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public function handle(OperationIdempotency $keys, TaskCancelIntent $cancel): void
    {
        $group = Task::topLevel()->find($this->groupId);
        // An ending already admitted or done, by this job or the caller's own retry, leaves nothing to start.
        if ($group === null || Operation::query()->where('kind', 'tasks.cancel')->where('target_type', 'task_group')->where('target_id', $group->id)
            ->where('id', '>', $this->provisionId)->where('status', '!=', OperationStatus::Failed)->exists()
            || ($group->taskable_id === null && ! TaskVm::query()->where('group_id', $group->id)->where('state', '!=', TaskVmState::Destroyed)->exists())) {
            return;
        }
        $key = 'provision-cancelled:'.$this->provisionId;
        try {
            $keys->under('tasks.cancel', $key, hash('sha256', $key), fn () => $cancel->start($group, 'task-provision', null));
        } catch (ResourceOperationException $refused) {
            // An uncertain Swamp start still recorded the ending; the Gateway settles its run.
            if (Operation::query()->where('kind', 'tasks.cancel')->where('idempotency_key', $key)->exists()) {
                return;
            }
            if (in_array($refused->errorCode, ['operation.target_busy', 'tasks.workspace_waiting'], true)) {
                throw $refused;
            }
            $this->failed($refused);
        }
    }

    public function failed(Throwable $failure): void
    {
        $group = Task::topLevel()->find($this->groupId);
        $reason = $failure instanceof ResourceOperationException ? $failure->errorCode : $failure->getMessage();
        if ($group !== null) {
            TaskAssistance::apply($group, AssistanceKind::RemovalFailure, reason: 'The ending of the cancelled provision was not admitted: '.$reason);
        }
    }
}
