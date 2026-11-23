<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\TaskCheckData;
use App\Data\Tasks\TaskData;
use App\Data\Tasks\TaskGroupData;
use App\Domain\Tasks\TaskGroupMetricsRefresher;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Models\Instance;
use App\Models\Task;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One page of task groups, newest first, read as list rows (docs/reference/tasks.md#list-tasks): the columns a row shows and no long
 * text or JSON, with each relation a row shows read once for the whole page.
 */
final readonly class ListTaskGroupsAction
{
    public const int DefaultLimit = 20;

    public const int MaxLimit = 100;

    /** A GitHub `owner/name`, as the pull request filter takes it. */
    public const string RepositoryRule = 'regex:/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?\/[A-Za-z0-9._-]{1,100}\z/';

    public function __construct(private RequireTasksExtensionAction $requireExtension, private TaskGroupMetricsRefresher $metrics) {}

    /**
     * Reads one row more than the page, so the caller knows whether a next page exists.
     *
     * @param  int|null  $before  the cursor: only groups with a lower ID
     * @param  int|null  $instanceId  only the groups whose task workspace is this Instance
     * @param  array{repository: string, number: int}|null  $pullRequest  only the groups whose published or watched pull request this is,
     *                                                                    with the repository matched in any case
     * @return array{groups: Collection<int, Task>, next_cursor: ?string}
     */
    public function execute(?int $projectId, ?TaskGroupStatus $status, int $limit = self::DefaultLimit, ?int $before = null, ?int $instanceId = null, ?array $pullRequest = null): array
    {
        // Both URL columns have an index on their lower-case form, so the filter reads only the matching rows.
        $url = $pullRequest === null ? null : strtolower('https://github.com/'.$pullRequest['repository'].'/pull/'.$pullRequest['number']);
        $this->requireExtension->execute();
        $rows = Task::topLevel()
            ->select(TaskGroupData::ListColumns)
            ->with([
                'project',
                'tasks' => static fn ($query) => $query->select(TaskData::ListColumns),
                'tasks.latestCheck' => static fn ($query) => $query->select(self::qualified('task_checks', TaskCheckData::ListColumns)),
                'reviewedCommits',
                'taskable' => static fn (MorphTo $morph) => $morph
                    ->constrain([Instance::class => static fn ($query) => $query->select(['id', 'project_id', 'node_id', 'task_workspace_owner_id'])])
                    ->morphWith([Instance::class => [
                        'routes' => static fn ($query) => $query->select(self::qualified('routes', ['id', 'domain', 'status', 'web_root'])),
                        'runtime' => static fn ($query) => $query->select(['id', 'instance_id', 'node_id']),
                    ]]),
            ])
            ->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))
            ->when($instanceId !== null, static fn ($query) => $query->where('taskable_type', (new Instance)->getMorphClass())->where('taskable_id', $instanceId))
            ->when($status instanceof TaskGroupStatus, static fn ($query) => $query->where('status', $status))
            ->when($url !== null, static fn ($query) => $query->whereIn('id', Task::topLevel()->select('id')->whereRaw('lower(pr_url) = ?', [$url])
                ->union(Task::topLevel()->select('id')->whereRaw('lower(watched_pr_url) = ?', [$url]))))
            ->when($before !== null, static fn ($query) => $query->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get();
        $page = $rows->take($limit)->values();
        $this->metrics->refreshMany($page);

        return ['groups' => $page, 'next_cursor' => $rows->count() > $limit ? (string) $page->last()?->id : null];
    }

    /**
     * The subtasks of one group in position order, read as list rows. A Project other than the group's lists none.
     *
     * @return Collection<int, Task>
     */
    public function subtasks(Task $group, ?TaskStatus $status, ?int $projectId): Collection
    {
        $this->requireExtension->execute();
        if ($projectId !== null && $projectId !== $group->project_id) {
            return new Collection;
        }

        return $group->tasks()->listedSubtasks()
            ->select(TaskData::ListColumns)
            ->with(['latestCheck' => static fn ($query) => $query->select(self::qualified('task_checks', TaskCheckData::ListColumns))])
            ->when($status instanceof TaskStatus, static fn ($query) => $query->where('status', $status))
            ->get();
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private static function qualified(string $table, array $columns): array
    {
        return array_map(static fn (string $column): string => $table.'.'.$column, $columns);
    }
}
