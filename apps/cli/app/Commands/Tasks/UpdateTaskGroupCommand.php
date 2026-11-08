<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tasks\UpdateTaskRequest;
use Orbit\Sdk\Responses\Tasks\SubtaskResponse;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;
use Orbit\Sdk\Responses\Tasks\TaskRecordResponse;

final class UpdateTaskGroupCommand extends TaskCommand
{
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
        {--json : Return machine-readable JSON}';

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
        if (in_array(false, [$taskId, $title, $brief, $position, $deliverables, $topology], true)) {
            return self::FAILURE;
        }
        if ($status !== null && ! in_array($status, self::GROUP_STATUSES, true)) {
            return $this->renderGatewayFailure('task.status_invalid', 'Unknown task status.');
        }
        if ($this->option('preview') && $this->option('no-preview')) {
            return $this->renderGatewayFailure('task.preview_conflict', 'Use either --preview or --no-preview.');
        }
        $preview = $this->option('preview') ? true : ($this->option('no-preview') ? false : null);
        $changed = $title !== null || $brief !== null || $status !== null || $preview !== null || $position !== null || $deliverables !== null || $topology !== null;
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
            $taskId, $title, $brief, is_string($status) ? $status : null, $preview, $position, $deliverables, $topology,
        ), TaskRecordResponse::class, ['Update task', 'Updating task', 'Updated task']);

        return $task instanceof SubtaskResponse ? $this->renderSubtask($task) : ($task instanceof TaskGroupResponse ? $this->renderGroup($task) : self::FAILURE);
    }
}
