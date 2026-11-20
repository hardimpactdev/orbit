<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Tasks\ListTasksRequest;
use Orbit\Sdk\Responses\Tasks\SubtaskResponse;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;
use Orbit\Sdk\Responses\Tasks\TaskGroupsResponse;

final class ListTaskGroupsCommand extends TaskCommand
{
    #[\Override]
    protected $signature = 'task:list
        {--parent= : Numeric parent Task ID}
        {--project= : Only groups of this numeric Project ID}
        {--status= : Only groups in this status}
        {--limit= : Groups per page, 1 to 100 (default 20)}
        {--cursor= : Read the page after the one that returned this cursor}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List task groups, newest first.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $parentId = $this->option('parent') === null ? null : $this->idOption('parent', 'Parent task', 'parent');
        if ($parentId === false) {
            return self::FAILURE;
        }
        $project = $this->option('project');
        $projectId = null;

        if ($project !== null) {
            $projectId = filter_var($project, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if (! is_int($projectId)) {
                return $this->renderGatewayFailure('task.project_invalid', 'Project ID must be a positive integer.');
            }
        }

        $status = $this->option('status');

        if ($status !== null && ! in_array($status, self::GROUP_STATUSES, true)) {
            return $this->renderGatewayFailure('task.status_invalid', 'Status must be one of '.implode(', ', self::GROUP_STATUSES).'.');
        }

        $limit = $this->option('limit');
        $cursor = $this->option('cursor');

        if ($limit !== null && (preg_match('/\A[1-9][0-9]*\z/', $limit) !== 1 || (int) $limit > 100)) {
            return $this->renderGatewayFailure('input.invalid', '--limit must be a whole number from 1 to 100.', details: ['field' => 'limit']);
        }

        if ($cursor !== null && preg_match('/\A[1-9][0-9]*\z/', $cursor) !== 1) {
            return $this->renderGatewayFailure('input.invalid', '--cursor must be the next_cursor of an earlier page.', details: ['field' => 'cursor']);
        }

        if ($parentId !== null && ($limit !== null || $cursor !== null)) {
            return $this->renderGatewayFailure('input.invalid', '--limit and --cursor page task groups; --parent lists every subtask of one group.', details: ['field' => $limit !== null ? 'limit' : 'cursor']);
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $groups = $this->sendWithProgress(
            $connector,
            new ListTasksRequest($projectId, is_string($status) ? $status : null, $parentId, $limit === null ? null : (int) $limit, $cursor),
            TaskGroupsResponse::class,
            ['List task groups', 'Loading task groups', 'Loaded task groups'],
        );

        if (! $groups instanceof TaskGroupsResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($groups->toArray());

            return self::SUCCESS;
        }

        if ($parentId !== null) {
            foreach ($groups->taskGroups as $task) {
                if ($task instanceof SubtaskResponse) {
                    $this->renderSubtask($task);
                }
            }

            return self::SUCCESS;
        }

        $rows = array_map(static fn (TaskGroupResponse $group): array => [
            $group->id,
            $group->title,
            $group->project ?? $group->projectId,
            $group->status,
            // A task with no subtask has no subtask count (docs/reference/tasks.md#a-task-with-no-subtask).
            $group->work === null ? count(array_filter($group->subtasks, static fn (SubtaskResponse $task): bool => $task->status === 'completed')).'/'.count($group->subtasks) : null,
            self::askingKind($group->assistanceRequested, $group->assistanceKind),
            self::assistanceSummary($group->assistanceRequested, $group->assistanceKind, $group->assistanceQuestion, $group->assistanceReason),
        ], array_values(array_filter($groups->taskGroups, static fn ($task): bool => $task instanceof TaskGroupResponse)));

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['ID', 'Title', 'Project', 'Status', 'Subtasks', 'Kind', 'Assistance'],
            $rows,
            'No task groups.',
        ));

        if ($groups->nextCursor !== null) {
            $this->line("More task groups: --cursor={$groups->nextCursor}");
        }

        $this->writeHumanMessage("Request ID: {$groups->requestId}");

        return self::SUCCESS;
    }
}
