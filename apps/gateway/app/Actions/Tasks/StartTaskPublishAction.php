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
    public function execute(Task $group, StartTaskPublishData $input, string $triggeredBy, ?string $requestId, ?int $doneCheck = null, bool $byItself = false): Operation
    {
        $this->extension->execute();
        $withheld = null;
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
            // A group that has its pull request publishes its done check again: the push updates the pull request's branch.
            $check = $this->done->check($group, republish: true)
                ?? throw new ResourceOperationException('tasks.not_done', 'The task group\'s work is not done: publish needs its done check, or name a check with --check.', 409);
            $input = TaskDonePublication::for($group, $check);
            $doneCheck = $check->id;
        } elseif ($input->pull_request === null && $group->loadMissing('taskable', 'project')->pr_url === null
            && TaskInstanceWorkspace::forGroup($group) !== null) {
            // A named check without a text opens the pull request when it is the reviewed done check, as the release does; any other check only pushes, and the Operation says why.
            $done = $this->done->check($group);
            $withheld = TaskDonePublication::withheld($group, $input->check, $done);
            if ($withheld === null && $done !== null) {
                $input = TaskDonePublication::for($group, $done);
                $doneCheck = $done->id;
            }
        }

        // Every pull request text of a group that names its issue closes it, whoever wrote the text (docs/reference/tasks.md#closes-the-issue).
        $text = $input->pull_request;
        if ($text !== null) {
            $input = new StartTaskPublishData($input->check, [...$text, 'body' => TaskDonePublication::closing($group, $text['body'])]);
        }

        return $this->publish->start($group, $input, $triggeredBy, $requestId, $doneCheck, $byItself, $withheld);
    }
}
