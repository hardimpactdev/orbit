<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Instances\InstanceContainer;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\TaskAgent;
use App\Domain\Tasks\TaskDeliverable;
use App\Domain\Tasks\TaskGroupActivity;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskType;
use App\Domain\Tasks\TaskWorkReview;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskCheck;
use Illuminate\Database\Eloquent\Collection;
use Orbit\Extensions\Conn\Data\ConnTaskData;
use Orbit\Extensions\Conn\Data\ConnTaskDetailData;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Spatie\LaravelData\Optional;

/**
 * The read model of the Conn Tasks panel ([Tasks panel](/reference/conn#tasks-panel)). It reads the tasks the task list and show read
 * and adds what a device needs: what each task asks, its workspace with the Conn server the caller reaches, and each agent thread
 * with the Conn thread it opens. It writes nothing.
 */
final readonly class ConnTaskPanel
{
    public function __construct(private ConnServerAccess $access) {}

    /**
     * The rows of one page of top-level tasks. Each relation a row shows is read once for the whole page.
     *
     * @param  Collection<int, Task>  $groups  with their subtasks loaded
     * @return list<ConnTaskData>
     */
    public function rows(Node $caller, Collection $groups): array
    {
        $instances = $this->instances($groups);
        $environments = $this->environments($caller, $instances);
        $current = AgentThread::query()->whereIn('id', $groups->flatMap(static fn (Task $group): array => self::currentThreadIds($group))->all())
            ->get(['id', 'questions'])->keyBy('id');
        $activity = TaskGroupActivity::of(array_values($groups->modelKeys()));

        return array_values($groups->map(fn (Task $group): ConnTaskData => $this->row($group, $instances, $environments, $current, $activity[$group->id] ?? null))->all());
    }

    /**
     * One top-level task with its subtasks, or the work of a task with no subtask, its metrics and agents. The task's metrics must be
     * refreshed (TaskGroupMetricsRefresher).
     */
    public function detail(Node $caller, Task $group): ConnTaskDetailData
    {
        $groups = new Collection([$group]);
        $instances = $this->instances($groups);
        $environments = $this->environments($caller, $instances);
        $threads = AgentThread::query()->where('task_group_id', $group->id)->orderBy('id')->get();
        $current = $threads->keyBy('id');
        $environment = $group->taskable_type === (new Instance)->getMorphClass() ? $environments->get($group->taskable_id) : null;
        $row = $this->row($group, $instances, $environments, $current, TaskGroupActivity::of([$group->id])[$group->id] ?? null);
        $work = self::work($group);
        $subtasks = self::subtasks($group);

        return new ConnTaskDetailData(
            row: $row,
            brief: $group->brief,
            // A task without subtasks does its own work: with its work row, or before its draft has one.
            execution: ['implementer_mode' => $subtasks->isEmpty() ? 'task' : 'per_subtask'],
            metrics: ['duration_ms' => $group->duration_ms, 'tokens' => $group->tokens, 'lines_added' => $group->lines_added, 'lines_deleted' => $group->lines_deleted],
            subtasks: array_values($subtasks->map(static fn (Task $task): array => [
                'id' => $task->id,
                'position' => $task->position,
                'title' => $task->title,
                'brief' => $task->brief,
                'deliverables' => self::deliverables($task),
                'status' => $task->status->value,
                'attention' => self::attention($task, [$task->implementer_agent_thread_id], $current),
                'current_implementer_agent_id' => $task->implementer_agent_thread_id,
                // Orbit records no line change per subtask: the task's lines are one snapshot of its whole branch.
                'metrics' => ['duration_ms' => $task->duration_ms, 'tokens' => $task->tokens, 'lines_added' => null, 'lines_deleted' => null],
            ])->all()),
            agents: $this->agents($group, $threads, $environment, $work),
            work: $work instanceof Task ? [
                'status' => $work->status->value,
                'review' => TaskWorkReview::of(new Collection([$group]))[$group->id],
                'deliverables' => self::deliverables($work),
                'current_implementer_agent_id' => $work->implementer_agent_thread_id,
                'check' => ($check = $work->latestCheck) instanceof TaskCheck ? [
                    'id' => $check->id, 'kind' => $check->kind->value, 'status' => $check->status->value, 'started_at' => $check->started_at->toIso8601String(),
                    'finished_at' => $check->finished_at?->toIso8601String(), 'exit_code' => $check->exit_code, 'failed_step' => $check->failed_step,
                ] : null,
            ] : new Optional,
        );
    }

    /**
     * @param  Collection<int, Instance>  $instances
     * @param  Collection<int, ConnEnvironment>  $environments
     * @param  Collection<int, AgentThread>  $current
     */
    private function row(Task $group, Collection $instances, Collection $environments, Collection $current, ?string $lastActivity): ConnTaskData
    {
        $instance = $group->taskable_type === (new Instance)->getMorphClass() ? $instances->get($group->taskable_id) : null;
        $work = self::work($group);
        $subtasks = self::subtasks($group);
        $counts = ['total' => $subtasks->count(), 'todo' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0, 'cancelled' => 0];
        foreach ($subtasks as $task) {
            $counts[match ($task->subtaskStatus()) {
                TaskStatus::Todo => 'todo',
                TaskStatus::Reserved, TaskStatus::Running, TaskStatus::Reviewing => 'running',
                TaskStatus::Completed => 'completed',
                TaskStatus::Failed => 'failed',
                TaskStatus::Cancelled => 'cancelled',
            }]++;
        }

        return new ConnTaskData(
            id: $group->id,
            title: $group->title,
            status: $group->status->value,
            // The work of a task with no subtask asks as the task: its own call for help follows the task's.
            attention: self::attention($group, self::currentThreadIds($group), $current) ?? ($work instanceof Task ? self::attention($work, [], $current) : null),
            project: ['id' => $group->project->id, 'slug' => $group->project->slug, 'name' => $group->project->name, 'code' => $group->project->code],
            workspace: $instance instanceof Instance ? [
                'instance_id' => $instance->id,
                'name' => $instance->name,
                'status' => $instance->status->value,
                'power_state' => $instance->runtime?->power_state->value,
                'node' => ['id' => $instance->node->id, 'name' => $instance->node->name],
                'branch' => $instance->branch,
                'conn_environment_id' => $environments->get($instance->id)?->environment_id,
            ] : null,
            pullRequest: self::pullRequest($group),
            subtaskCounts: $counts,
            createdAt: $group->created_at?->toIso8601String(),
            updatedAt: $group->updated_at?->toIso8601String(),
            lastActivityAt: $lastActivity,
            prOpenedAt: $group->pr_opened_at?->toIso8601String(),
            deployedAt: $group->deployed_at?->toIso8601String(),
            workStatus: $work instanceof Task ? $work->status->value : new Optional,
        );
    }

    /**
     * Every started thread of the task, oldest first. A thread's attempt counts the started threads of its role on its subtask. A
     * thread on the work of a task with no subtask names no subtask: the work's ID is no route member.
     *
     * @param  Collection<int, AgentThread>  $threads
     * @return list<array<string, mixed>>
     */
    private function agents(Task $group, Collection $threads, ?ConnEnvironment $environment, ?Task $work): array
    {
        $implementers = $group->tasks->pluck('implementer_agent_thread_id')->filter()->all();
        $attempts = [];
        $agents = [];
        foreach ($threads->reject(static fn (AgentThread $thread): bool => $thread->isPending()) as $thread) {
            $slot = $thread->role.':'.($thread->task_id ?? '');
            $attempts[$slot] = ($attempts[$slot] ?? 0) + 1;
            $agents[] = [
                'id' => $thread->id,
                'role' => $thread->role,
                'subtask_ids' => $thread->task_id === null || $thread->task_id === $work?->id ? [] : [$thread->task_id],
                'current' => in_array($thread->id, $implementers, true) || $thread->id === $group->reviewer_agent_thread_id,
                'attempt' => $attempts[$slot],
                'provider' => self::provider($thread),
                'model' => $thread->model,
                'effort' => $thread->effort,
                'state' => $thread->state?->value,
                'needs_input' => self::asks($thread) && $thread->state === AgentThreadState::AskingForInput,
                'created_at' => $thread->created_at?->toIso8601String(),
                'observed_at' => $thread->observed_at?->toIso8601String(),
                // The T3 thread id is the external id: the row id is Orbit's own and never a thread id.
                'conn' => $environment instanceof ConnEnvironment ? ['environment_id' => $environment->environment_id, 'thread_id' => $thread->external_id] : null,
            ];
        }

        return $agents;
    }

    /**
     * What the task or subtask asks: a current thread's question first, then its own call for help.
     *
     * @param  list<int|null>  $threadIds  its current threads
     * @param  Collection<int, AgentThread>  $threads  by id, holding at least those threads
     * @return array{kind: string, question: ?string, reason: ?string}|null
     */
    private static function attention(Task $task, array $threadIds, Collection $threads): ?array
    {
        foreach ($threadIds as $id) {
            $thread = $id === null ? null : $threads->get($id);
            if ($thread instanceof AgentThread && self::asks($thread)) {
                $question = $thread->questions[0]['question'] ?? null;

                return ['kind' => 'question', 'question' => is_string($question) ? $question : null, 'reason' => null];
            }
        }

        return $task->assistance_requested && $task->assistance_kind !== null
            ? ['kind' => $task->assistance_kind->value, 'question' => $task->assistance_question, 'reason' => $task->assistance_reason]
            : null;
    }

    /** The work of a task with no subtask ({@see TaskType::Work}), or null for a task with subtasks. */
    private static function work(Task $group): ?Task
    {
        return $group->tasks->first(static fn (Task $task): bool => $task->type === TaskType::Work);
    }

    /** @return Collection<int, Task> the subtasks a read lists, in position order: never the work of a task with no subtask */
    private static function subtasks(Task $group): Collection
    {
        return $group->tasks->reject(static fn (Task $task): bool => $task->type === TaskType::Work)->values();
    }

    /** @return list<array{id: string, type: string, description: string}> */
    private static function deliverables(Task $task): array
    {
        return array_map(static fn (TaskDeliverable $deliverable): array => [
            'id' => $deliverable->id, 'type' => $deliverable->type->value, 'description' => $deliverable->description,
        ], $task->deliverableList());
    }

    private static function asks(AgentThread $thread): bool
    {
        return is_array($thread->questions) && $thread->questions !== [];
    }

    /** @return list<int> the implementer threads of the subtasks and the reviewer thread */
    private static function currentThreadIds(Task $group): array
    {
        return array_values(array_filter([...$group->tasks->pluck('implementer_agent_thread_id')->all(), $group->reviewer_agent_thread_id], is_int(...)));
    }

    /** @return array{number: ?int, url: string, state: ?string}|null */
    private static function pullRequest(Task $group): ?array
    {
        if ($group->watched_pr_number !== null && is_string($group->watched_pr_url)) {
            return ['number' => $group->watched_pr_number, 'url' => $group->watched_pr_url, 'state' => $group->watched_pr_state];
        }
        if (! is_string($group->pr_url) || $group->pr_url === '') {
            return null;
        }

        return ['number' => preg_match('~/pull/(\d+)~', $group->pr_url, $match) === 1 ? (int) $match[1] : null, 'url' => $group->pr_url, 'state' => null];
    }

    /** The provider of the copy instance the thread started on, else the one its model names. */
    private static function provider(AgentThread $thread): ?string
    {
        foreach (InstanceContainer::CopyProviders as $provider => $efforts) {
            foreach ($efforts as $effort) {
                if ($thread->provider_instance === InstanceContainer::providerInstance($provider, $effort)) {
                    return $provider;
                }
            }
        }

        foreach (TaskAgent::ModelPrefixes as $provider => $prefix) {
            if (str_starts_with((string) $thread->model, $prefix)) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * The task workspaces of the groups that still exist, by id.
     *
     * @param  Collection<int, Task>  $groups
     * @return Collection<int, Instance>
     */
    private function instances(Collection $groups): Collection
    {
        $ids = $groups->where('taskable_type', (new Instance)->getMorphClass())->pluck('taskable_id')->filter()->unique()->values()->all();

        return Instance::query()->whereIn('id', $ids)->with(['node:id,name', 'runtime'])
            ->get(['id', 'node_id', 'name', 'status', 'branch'])->keyBy('id');
    }

    /**
     * The Conn server of each workspace, only where the caller reaches it by the rule of the thread index (ConnServerAccess).
     *
     * @param  Collection<int, Instance>  $instances
     * @return Collection<int, ConnEnvironment> by Instance id
     */
    private function environments(Node $caller, Collection $instances): Collection
    {
        return $this->access->visible($caller, ConnEnvironment::query()->whereIn('instance_id', $instances->modelKeys()))
            ->orderBy('id')->get(['id', 'environment_id', 'instance_id', 'node_id'])->keyBy('instance_id');
    }
}
