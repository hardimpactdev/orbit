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

    #[\Override]
    protected $signature = 'task:update
        {task? : Numeric Task ID}
        {--title= : New title}
        {--brief= : New brief}
        {--status= : Board status for the selected level}
        {--preview : Keep the parent sandbox running during review}
        {--no-preview : Allow the parent sandbox to park during review}
        {--position= : Child position, starting at 1}
        {--deliverables= : JSON file replacing the child deliverables}
        {--topology= : JSON array of required workload nodes}
        {--implementer-provider= : Implementer provider in a task copy, such as claude}
        {--implementer-model= : Implementer model in a task copy, such as claude-opus-5-5}
        {--implementer-effort= : Implementer effort in a task copy: low, medium, high, xhigh, or max}
        {--reviewer-provider= : Reviewer provider in a task copy}
        {--reviewer-model= : Reviewer model in a task copy}
        {--reviewer-effort= : Reviewer effort in a task copy}
        {--clear-implementer-agent : Clear the implementer provider, model and effort, so the Project or Gateway defaults apply}
        {--clear-reviewer-agent : Clear the reviewer provider, model and effort}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Update a task or subtask.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $taskId = $this->idArgument('task', 'Task', 'task');
        $title = $this->optionalText($this->option('title'), 'Title', 'title', self::TITLE_MAX);
        $brief = $this->optionalText($this->option('brief'), 'Brief', 'brief', self::BRIEF_MAX);
        $status = $this->option('status');
        $position = $this->option('position') === null ? null : $this->idOption('position', 'Position', 'position');
        $deliverables = $this->deliverablesFile();
        $topology = $this->topologyOption($this->option('topology'));
        $agents = $this->taskAgentsOption('', 'task', clearable: true);
        if (in_array(false, [$taskId, $title, $brief, $position, $deliverables, $topology, $agents], true)) {
            return self::FAILURE;
        }
        if ($status !== null && ! in_array($status, self::GROUP_STATUSES, true)) {
            return $this->renderGatewayFailure('task.status_invalid', 'Unknown task status.');
        }
        if ($this->option('preview') && $this->option('no-preview')) {
            return $this->renderGatewayFailure('task.preview_conflict', 'Use either --preview or --no-preview.');
        }
        $preview = $this->option('preview') ? true : ($this->option('no-preview') ? false : null);
        $changed = $title !== null || $brief !== null || $status !== null || $preview !== null || $position !== null || $deliverables !== null || $topology !== null || $agents !== null;
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
            $taskId, $title, $brief, is_string($status) ? $status : null, $preview, $position, $deliverables, $topology, $agents,
        ), TaskRecordResponse::class, ['Update task', 'Updating task', 'Updated task']);

        return $task instanceof SubtaskResponse ? $this->renderSubtask($task) : ($task instanceof TaskGroupResponse ? $this->renderGroup($task) : self::FAILURE);
    }
}
