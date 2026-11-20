<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Operations\StartOperationAction;
use App\Data\Tasks\StartTaskPublishData;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Records the exact checked candidate. Swamp owns Git and GitHub effects. */
final readonly class TaskPublishIntent
{
    public function __construct(private StartOperationAction $start, private OperationIdempotency $idempotency, private TaskCheckedCandidate $candidates, private TaskGroupDone $done) {}

    /**
     * @param  int|null  $doneCheck  the done check a release or the tick read before the lock; it must still be the done check under the row lock
     * @param  bool  $byItself  the tick's admission, which also needs the group and that check in `pr_when_done` under the row lock
     * @param  string|null  $withheld  why a publish of a named check opens no pull request; the Operation records it beside the intent
     */
    public function start(Task $group, StartTaskPublishData $input, string $caller, ?string $request, ?int $doneCheck = null, bool $byItself = false, ?string $withheld = null): Operation
    {
        if (($existing = $this->idempotency->replay('tasks.publish')) !== null) {
            return $existing;
        }

        return app(TaskExecutionLock::class)->synchronized($group->id, fn (): Operation => $this->admit($group, $input, $caller, $request, $doneCheck, $byItself, $withheld));
    }

    private function admit(Task $group, StartTaskPublishData $input, string $caller, ?string $request, ?int $doneCheck, bool $byItself, ?string $withheld): Operation
    {
        if (($existing = $this->idempotency->replay('tasks.publish')) !== null) {
            return $existing;
        }
        $group->refresh()->load('taskable', 'project');
        $this->assertIdle($group);
        if (in_array($group->status, TaskGroupStatus::endedStatuses(), true)) {
            throw new ResourceOperationException('tasks.group_closed', 'This task group has ended.', 409);
        }
        // A publish runs only from the group's task copy; task VM workspaces are removed (docs/reference/old-paths.md).
        $copy = TaskInstanceWorkspace::forGroup($group)
            ?? throw new ResourceOperationException('tasks.no_workspace', 'The group requires its task copy.', 409);
        $instance = $copy->instance;
        self::refuseHeld($group->project);
        TaskTurn::refuseUnended($group);
        $workspace = $copy->publication();
        $check = TaskCheck::query()->whereKey($input->check)->whereHas('task', fn ($tasks) => $tasks->where('parent_id', $group->id))->first();
        if (! $check instanceof TaskCheck || ! $this->candidates->eligible($group, $check)) {
            throw new ResourceOperationException('tasks.commit_not_checked', 'Publish requires this group\'s passed handoff candidate and required CI.', 422);
        }
        TaskHandoffs::requireApproval($group, $check->task()->firstOrFail(), 'Publishing', $check->commit_sha);
        $repository = GitHubRepository::fromOrigin($group->project->repository_url);
        $base = $group->project->default_branch;
        if ($repository === null || ! is_string($base) || ! GitBranchName::isValid($base)) {
            throw new ResourceOperationException('tasks.publish_failed', 'The Project requires a GitHub repository and valid default branch.', 422);
        }

        // Only the vault key names are admitted. The publish step mints the token when it runs ({@see TaskGitHubTokens}).
        $token = ['vault' => 'orbit-control-inputs', 'key' => 'task-publish-token-'.Str::uuid()];
        $authorization = ['vault' => 'orbit-control-inputs', 'key' => 'task-publish-token-'.Str::uuid()];

        return DB::transaction(function () use ($group, $instance, $check, $repository, $base, $token, $authorization, $workspace, $input, $caller, $request, $doneCheck, $byItself, $withheld): Operation {
            Task::topLevel()->whereKey($group->id)->lockForUpdate()->firstOrFail();
            if (($existing = $this->idempotency->replay('tasks.publish')) !== null) {
                return $existing;
            }
            $this->assertIdle($group);
            if ($doneCheck !== null) {
                $this->assertStillDone($group->id, $doneCheck, $byItself);
            }
            $branch = 'task-'.$group->id;
            $commit = ValidatedData::string($check->commit_sha);
            $tree = ValidatedData::string($check->tree_after);
            $operation = $this->start->execute('tasks.publish', 'task_group', $group->id, $instance->node_id, [
                ...$input->toArray(), 'group_id' => $group->id, 'instance_id' => $instance->id, 'node_id' => $instance->node_id,
                'checkout_path' => TaskInstanceWorkspace::Root, 'commit' => $commit, 'tree' => $tree,
                'repository' => $repository->owner.'/'.$repository->name, 'base' => $base, 'branch' => $branch,
                'remote' => 'https://github.com/'.$repository->owner.'/'.$repository->name.'.git',
                'refspec' => $commit.':refs/heads/'.$branch,
                'object_format' => strlen($commit) === 64 ? 'sha256' : 'sha1',
                'pull_head' => $repository->owner.':'.$branch, 'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node),
                'execution_secrets' => ['github_token' => $token, 'git_authorization' => $authorization],
                'workspace' => $workspace, ...($withheld === null ? [] : [TaskPublishProjection::Withheld => $withheld]),
                ...($doneCheck === null ? [] : [TaskPublishProjection::DoneCheck => $doneCheck]),
            ], $caller, $request, lockKey: OperationStore::lockKey('task_group', $group->id));
            // A publish that is admitted ends a refused automatic publish (docs/reference/tasks.md#a-refused-automatic-publish).
            Task::topLevel()->whereKey($group->id)->whereNotNull('publish_refusal')->update(['publish_refusal' => null]);

            return $operation;
        });
    }

    /**
     * A task copy publishes only for a nested-fleet trial Project or a Project on `orbit.tasks.copy_publish_projects`: the list holds every
     * other Project's repository back until an operator releases it (docs/reference/tasks.md#task-copy). The checked commit stays in the copy.
     */
    public static function refuseHeld(Project $project): void
    {
        $listed = config('orbit.tasks.copy_publish_projects');
        if ($project->nested_fleet || (is_array($listed) && array_is_list($listed) && in_array($project->id, $listed, true))) {
            return;
        }
        throw new ResourceOperationException('tasks.copy_publish_held',
            "Project [{$project->slug}] is not released for publishing from task copies yet. The checked commit stays in the task copy; publish it once the Project is released.", 409);
    }

    /**
     * Under the group's row lock, a subtask added or a turn started since the done read changes the answer, and for the tick so does a
     * mode change: each refuses `tasks.not_done`, which the tick skips quietly.
     */
    private function assertStillDone(int $groupId, int $doneCheck, bool $byItself): void
    {
        $locked = Task::topLevel()->with('project', 'taskable', 'tasks')->findOrFail($groupId);
        $check = $this->done->check($locked, republish: ! $byItself);
        if ($check?->id !== $doneCheck) {
            throw new ResourceOperationException('tasks.not_done', 'The task group\'s work is no longer done with this check.', 409);
        }
        if ($byItself && (TaskPrModeChoice::of($locked)->mode !== TaskPrMode::PrWhenDone || $check->pr_mode !== TaskPrMode::PrWhenDone)) {
            throw new ResourceOperationException('tasks.not_done', 'The task group no longer publishes by itself.', 409);
        }
    }

    private function assertIdle(Task $group): void
    {
        if (Operation::query()->where('target_type', 'task_group')->where('target_id', $group->id)
            ->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
            throw new ResourceOperationException('operation.target_busy', 'Another Task Operation owns this group.', 409);
        }
    }
}
