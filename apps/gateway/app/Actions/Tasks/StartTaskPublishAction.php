<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\StartTaskPublishData;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskDonePublication;
use App\Domain\Tasks\TaskGroupDone;
use App\Domain\Tasks\TaskInstanceWorkspace;
use App\Domain\Tasks\TaskPublishIntent;
use App\Models\Operation;
use App\Models\Task;
use Illuminate\Validation\ValidationException;

final readonly class StartTaskPublishAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private TaskPublishIntent $publish, private TaskGroupDone $done, private OperationIdempotency $idempotency) {}

    /** @param  int|null  $doneCheck  the done check a release or the tick read; the admission refuses `tasks.not_done` when it is no longer the done check */
    public function execute(Task $group, StartTaskPublishData $input, string $triggeredBy, ?string $requestId, ?int $doneCheck = null): Operation
    {
        $this->extension->execute();
        if ($input->check === null) {
            // Only a task copy's group has a done check to release; any other group names its check, as before.
            if (TaskInstanceWorkspace::forGroup($group->loadMissing('taskable', 'project')) === null) {
                throw ValidationException::withMessages(['check' => ['The check field is required.']]);
            }
            // A repeat of a release replays its Operation, also once the published group is no longer waiting.
            if (($existing = $this->idempotency->replay('tasks.publish')) !== null) {
                return $existing;
            }
            // The release of a waiting group: its done check, as the automatic publish would send it. The admission checks the candidate again under the group lock.
            $check = $this->done->check($group)
                ?? throw new ResourceOperationException('tasks.not_done', 'The task group\'s work is not done: publish needs its done check, or name a check with --check.', 409);
            $input = TaskDonePublication::for($group, $check);
            $doneCheck = $check->id;
        }

        return $this->publish->start($group, $input, $triggeredBy, $requestId, $doneCheck);
    }
}
