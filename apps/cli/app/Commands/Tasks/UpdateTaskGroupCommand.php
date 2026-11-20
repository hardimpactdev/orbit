<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Commands\Concerns\ReadsTaskAgentOptions;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tasks\UpdateTaskRequest;
use Orbit\Sdk\Responses\Tasks\SubtaskResponse;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;
use Orbit\Sdk\Responses\Tasks\TaskRecordResponse;

final class UpdateTaskGroupCommand extends TaskCommand
{
    use ReadsTaskAgentOptions;

    public const int REASON_MAX = 500;

    #[\Override]
    protected $signature = 'task:update
        {task? : Numeric Task ID}
        {--title= : New title}
        {--brief= : New brief}
        {--status= : Board status for the selected level}
        {--preview : Keep the parent sandbox running during review}
        {--no-preview : Allow the parent sandbox to park during review}
        {--position= : Child position, starting at 1}
        {--deliverables= : JSON file replacing the deliverables of a subtask, or of a task with no subtask}
        {--work-status= : Subtask status for the work of a task with no subtask, such as completed}
        {--reason= : Why a correction after a failed check replaces the deliverables of a started subtask in a task copy}
        {--implementer-provider= : Implementer provider in a task copy: claude, codex, or antigravity}
        {--implementer-model= : Implementer model in a task copy, such as claude-opus-5-5}
        {--implementer-effort= : Implementer effort in a task copy: low, medium, high, xhigh, or max; codex adds ultra, antigravity takes high only}
        {--reviewer-provider= : Reviewer provider in a task copy: claude, codex, or antigravity}
        {--reviewer-model= : Reviewer model in a task copy}
        {--reviewer-effort= : Reviewer effort in a task copy}
        {--clear-implementer-agent : Clear the implementer provider, model and effort, so the Project or Gateway defaults apply}
        {--clear-reviewer-agent : Clear the reviewer provider, model and effort}
        {--pr-mode= : review_before_pr or pr_when_done; only until the group has a pull request}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Update a task or subtask.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $taskId = $this->idArgument('task', 'Task', 'task');
        $title = $this->optionalText($this->option('title'), 'Title', 'title', self::TITLE_MAX);
        $brief = $this->optionalText($this->option('brief'), 'Brief', 'brief', self::BRIEF_MAX);
        $status = $this->option('status');
        $workStatus = $this->option('work-status');
        $position = $this->option('position') === null ? null : $this->idOption('position', 'Position', 'position');
        $deliverables = $this->deliverablesFile();
        $reason = $this->optionalText($this->option('reason'), 'Reason', 'reason', self::REASON_MAX);
        $agents = $this->taskAgentsOption('', 'task', clearable: true);
        $prMode = $this->prModeOption('pr-mode');
        if (in_array(false, [$taskId, $title, $brief, $position, $deliverables, $reason, $agents, $prMode], true)) {
            return self::FAILURE;
        }
        if ($status !== null && ! in_array($status, self::GROUP_STATUSES, true)) {
            return $this->renderGatewayFailure('task.status_invalid', 'Unknown task status.');
        }
        if ($workStatus !== null && ! in_array($workStatus, self::WORK_STATUSES, true)) {
            return $this->renderGatewayFailure('task.work_status_invalid', 'Work status must be one of '.implode(', ', self::WORK_STATUSES).'.');
        }
        if ($this->option('preview') && $this->option('no-preview')) {
            return $this->renderGatewayFailure('task.preview_conflict', 'Use either --preview or --no-preview.');
        }
        $preview = $this->option('preview') ? true : ($this->option('no-preview') ? false : null);
        $changed = $title !== null || $brief !== null || $status !== null || $preview !== null || $position !== null || $deliverables !== null || $agents !== null || $prMode !== null || $workStatus !== null;
        if (! $changed && ! $this->consoleMode()->mayPrompt) {
            return $this->renderGatewayFailure('task.update_required', 'Provide at least one task update.');
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $taskId ??= (int) $this->promptText('Task ID', 20);
        if (! $changed) {
            $title = $this->promptText('Title', self::TITLE_MAX);
        }
        $task = $this->sendWithProgress($connector, new UpdateTaskRequest(
            $taskId, $title, $brief, is_string($status) ? $status : null, $preview, $position, $deliverables, $agents, $prMode, $reason,
            is_string($workStatus) ? $workStatus : null,
        ), TaskRecordResponse::class, ['Update task', 'Updating task', 'Updated task']);

        return $task instanceof SubtaskResponse ? $this->renderSubtask($task) : ($task instanceof TaskGroupResponse ? $this->renderGroup($task) : self::FAILURE);
    }
}
