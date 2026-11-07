<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Data\Tasks\StartTaskCheckData;
use App\Data\Tasks\TaskTurnData;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmProviderWorkflowInput;
use App\Domain\TaskVms\TaskVmResumeIntent;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskReviewRound;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admits `tasks.review_round`: one run for one review round of the automatic mode. The shared task admission holds the lock, the busy
 * check and the replay; this class checks the head and captures the whole intent: the resume of the VM, the reviewer turn in a new
 * thread, and in rounds 1 and 2 the fix (the author turn, the local check and the push). Only vault key names are admitted: the run
 * writes the Pi token when it starts, and the comment and the push mint their tokens when they run. See docs/cli/task/review-round.mmd.
 */
final readonly class TaskReviewRoundIntent
{
    public function __construct(
        private TaskOperations $operations,
        private TaskPullRequestEvidence $evidence,
        private TaskCheckedCandidate $candidates,
        private TaskTurnIntent $turns,
        private TaskCheckIntent $checks,
        private TaskVmResumeIntent $resume,
    ) {}

    public function start(Task $group, string $head, string $caller, ?string $request): Operation
    {
        return DB::transaction(function () use ($group, $head, $caller, $request): Operation {
            $operation = $this->operations->start($group, OperationKinds::TasksReviewRound, [], static function (Task $group): void {}, $caller, $request, fn (Task $group): array => $this->capture($group, $head));
            if ($operation->wasRecentlyCreated) {
                $check = $operation->input['check'] ?? null;
                TaskReviewRound::query()->create(['task_group_id' => $group->id, 'head_sha' => $head, 'round' => $operation->input['round'], 'operation_id' => $operation->id,
                    'check_id' => is_array($check) ? $check['check_id'] : null]);
            }

            return $operation;
        });
    }

    /** @return array<string,mixed> */
    private function capture(Task $group, string $head): array
    {
        $target = $this->evidence->round($group, $head);
        $round = TaskReviewRound::query()->where('task_group_id', $group->id)->count() + 1;
        if ($round > TaskReviewRound::Limit) {
            throw new ResourceOperationException('tasks_pr.rounds_exhausted', 'The group used its review rounds.', 409);
        }
        $subtask = $group->tasks()->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Failed, TaskStatus::Cancelled])->orderBy('position')->orderBy('id')->first();
        if (! $subtask instanceof Task || ! $this->candidate($group, $subtask, $head)) {
            throw new ResourceOperationException('tasks_pr.no_checked_candidate', 'The head is not a passed handoff candidate of the current subtask, or a head that an earlier round pushed.', 409);
        }
        $group->load('project', 'taskable');
        $instance = $group->taskable;
        $vm = TaskVm::forGroup($group);
        $repository = GitHubRepository::fromOrigin($group->project->repository_url);
        if (! $instance instanceof Instance || $instance->task_workspace_owner_id !== $group->id || ! $vm instanceof TaskVm || $vm->node_id !== $instance->node_id || $instance->checkout_path === '' || $repository === null) {
            throw new ResourceOperationException('tasks.no_workspace', 'The group requires its owned task VM workspace.', 409);
        }
        if (! in_array($vm->state, [TaskVmState::Parked, TaskVmState::Ready], true)) {
            throw new ResourceOperationException('tasks_pr.vm_unavailable', 'The round needs the task VM of the group parked or running.', 409);
        }
        $author = $round < TaskReviewRound::Limit ? ($subtask->implementer_agent_thread_id ?? throw new ResourceOperationException('tasks_pr.no_author_thread', 'The current subtask has no author thread.', 409)) : null;
        $resume = TaskVmProviderWorkflowInput::envelope($this->resume->capture($vm));
        $turn = fn (TaskTurnData $data): array => array_diff_key($this->turns->capture($group, $data, static fn (): string => $head), ['execution_secrets' => true]);
        $fix = $author === null ? [] : [
            'fix' => $turn(new TaskTurnData($subtask->id, 'implementer', TaskReviewFixPrompt::for($round, $head), (string) $author, null, null)),
            'check' => $this->checks->capture($group, new StartTaskCheckData($subtask->id, TaskCheckKind::Handoff), hostedCi: false),
        ];

        return [
            ...$target->intent($group), 'node_id' => $instance->node_id, 'round' => $round, 'head' => $head,
            'resume' => $resume['intent'],
            'review' => $turn(new TaskTurnData($subtask->id, 'reviewer', TaskReviewRoundPrompt::for($head, $round), 'new', null, null)),
            ...$fix,
            'publish' => TaskPublishIntent::destination($group, $instance, $repository, app(SwampSshExecutionInput::class)->forNode($instance->node)),
            // Only vault key names are admitted. The Pi token is written when the run starts ({@see TaskReviewRoundProjection::workflowInput()}),
            // and the comment and the push mint their tokens when they run ({@see TaskGitHubTokens}).
            'execution_secrets' => ['pi_token' => ['vault' => 'orbit-control-inputs', 'key' => 'task-vm-pi-token-'.$vm->id],
                'write_token' => ['vault' => 'orbit-control-inputs', 'key' => 'pr-comment-write-'.Str::uuid()]],
            'resume_secrets' => $resume['execution_secrets'],
            'publish_secrets' => ['github_token' => ['vault' => 'orbit-control-inputs', 'key' => 'task-publish-token-'.Str::uuid()],
                'git_authorization' => ['vault' => 'orbit-control-inputs', 'key' => 'task-publish-token-'.Str::uuid()]],
        ];
    }

    /** The head is a passed handoff candidate of the current subtask, or the commit that an earlier round of the group pushed. */
    private function candidate(Task $group, Task $subtask, string $head): bool
    {
        return TaskReviewRound::query()->where('task_group_id', $group->id)->where('pushed_commit', $head)->exists()
            || $this->candidates->onHead($group, [$subtask->id], $head);
    }
}
