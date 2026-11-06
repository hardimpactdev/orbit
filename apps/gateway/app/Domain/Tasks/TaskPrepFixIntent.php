<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Operations\StartOperationAction;
use App\Data\Tasks\StartTaskPrepFixData;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmProviderWorkflowInput;
use App\Domain\TaskVms\TaskVmResumeIntent;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\Tasks\Pi\PiConnection;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admits `tasks:prep-fix`: one run that applies the review of the owner to a parked prep group. It captures the whole intent
 * in one transaction, so no step of the run calls the Gateway but the push token mint. See docs/cli/tasks/prep-fix.mmd.
 */
final readonly class TaskPrepFixIntent
{
    /** The review rounds of the parent workflow `operation-task-prep-fix`; its steps are written out, so this number is part of it. */
    public const int Rounds = 2;

    public function __construct(private StartOperationAction $start, private OperationIdempotency $idempotency, private TaskVmResumeIntent $resume, private SwampCommandStep $shared) {}

    public function start(Task $group, StartTaskPrepFixData $data, string $caller, ?string $request): Operation
    {
        if (($replay = $this->idempotency->replay('tasks.prep_fix')) !== null) {
            return $replay;
        }

        return app(TaskExecutionLock::class)->synchronized($group->id, fn (): Operation => DB::transaction(fn (): Operation => $this->admit($group, $data, $caller, $request)));
    }

    private function admit(Task $group, StartTaskPrepFixData $data, string $caller, ?string $request): Operation
    {
        if (($replay = $this->idempotency->replay('tasks.prep_fix')) !== null) {
            return $replay;
        }
        $group = Task::topLevel()->with('project', 'taskable')->lockForUpdate()->findOrFail($group->id);
        if (in_array($group->status, TaskGroupStatus::endedStatuses(), true)) {
            throw new ResourceOperationException('tasks.group_closed', 'This task group has ended.', 409);
        }
        $instance = $group->taskable;
        $vm = TaskVm::forGroup($group);
        if (! $instance instanceof Instance || $instance->task_workspace_owner_id !== $group->id || ! $vm instanceof TaskVm || $vm->node_id !== $instance->node_id || $instance->checkout_path === '') {
            throw new ResourceOperationException('tasks.no_workspace', 'The group requires its owned task VM workspace.', 409);
        }
        $prep = Operation::query()->where('kind', 'tasks.prep')->where('target_type', 'task_group')->where('target_id', $group->id)
            ->where('status', OperationStatus::Succeeded)->latest('id')->first();
        if (! $prep instanceof Operation || ! in_array($vm->state, [TaskVmState::Parked, TaskVmState::Ready], true) || ! $group->pr_url || $group->watched_pr_state !== 'open') {
            throw new ResourceOperationException('tasks.prep_fix_refused', 'The corrections need a prep group whose run succeeded, with its task VM parked and its draft pull request open.', 409);
        }
        if (Operation::query()->where('target_type', 'task_group')->where('target_id', $group->id)->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
            throw new ResourceOperationException('operation.target_busy', 'Another Task Operation owns this group.', 409);
        }
        $earlier = ValidatedData::object($prep->input);
        $author = AgentThread::query()->where('task_group_id', $group->id)->where('role', 'implementer')->find(ValidatedData::integer(ValidatedData::object($earlier['draft'])['thread_id']));
        $subtask = $group->tasks()->orderBy('position')->orderBy('id')->first();
        $repository = GitHubRepository::fromOrigin($group->project->repository_url);
        if (! $author instanceof AgentThread || ! $subtask instanceof Task || $repository === null || ! is_string($vm->pi_token) || strlen($vm->pi_token) < 32) {
            throw new ResourceOperationException('tasks.prep_fix_refused', 'The group has no author thread, subtask, GitHub repository or Pi credential to continue from.', 409);
        }
        $prompts = TaskPrepPrompts::for(ValidatedData::string($earlier['command']));
        $resume = TaskVmProviderWorkflowInput::envelope($this->resume->capture($vm));
        $instance->loadMissing('node');
        $ssh = app(SwampSshExecutionInput::class)->forNode($instance->node);
        $reviewers = array_map(fn (int $round): AgentThread => TaskPrepThreads::create($group, $subtask, $instance, 'reviewer'), range(1, self::Rounds));
        $turn = fn (AgentThread $thread, string $prompt, bool $new): array => [
            'new_session' => $new, 'group_id' => $group->id, 'instance_id' => $instance->id, 'node_id' => $instance->node_id,
            'subtask' => $subtask->id, 'thread_id' => $thread->id, 'checkout_path' => $instance->checkout_path,
            'session_id' => $thread->external_id, 'role' => $thread->role, 'prompt' => $prompt,
            'model' => $thread->model, 'effort' => $thread->effort, 'pi_host' => $instance->node->wireguard_ip,
            'pi_port' => app(PiConnection::class)->port(), 'candidate' => null,
            'deliverables' => $subtask->deliverables ?? [], 'ssh_execution' => $ssh,
        ];
        $branch = 'task-'.$group->id;
        // Only the vault key names are admitted. The push step mints the token when it runs ({@see TaskGitHubTokens}).
        $copy = ['vault' => 'orbit-control-inputs', 'key' => 'task-publish-token-'.Str::uuid()];
        $authorization = ['vault' => 'orbit-control-inputs', 'key' => 'task-publish-token-'.Str::uuid()];

        return $this->start->execute('tasks.prep_fix', 'task_group', $group->id, ValidatedData::integer($resume['intent']['node_id']), [
            'command' => $prompts->command, 'group_id' => $group->id, 'instance_id' => $instance->id, 'node_id' => $resume['intent']['node_id'],
            'corrections' => $data->corrections,
            'resume' => $resume['intent'],
            // The author thread continues its session; the Pi server answers it again after the resume.
            'author' => $turn($author, $prompts->corrections($data->corrections), false),
            'fix' => $turn($author, $prompts->fixCorrections(), false),
            'reviews' => array_map(fn (AgentThread $thread): array => $turn($thread, $prompts->reviewCorrections($data->corrections), true), $reviewers),
            'plan' => ['cases_file' => $prompts->cases()],
            'publish' => [
                'group_id' => $group->id, 'instance_id' => $instance->id, 'node_id' => $instance->node_id,
                'checkout_path' => $instance->checkout_path, 'repository' => $repository->owner.'/'.$repository->name,
                'base' => $group->project->default_branch, 'branch' => $branch, 'remote' => 'https://github.com/'.$repository->owner.'/'.$repository->name.'.git',
                'pull_head' => $repository->owner.':'.$branch, 'ssh_execution' => $ssh,
            ],
            'execution_secrets' => ['pi_token' => $this->shared->executionCopy('orbit-control-inputs', 'task-vm-pi-token-'.$vm->id, $vm->pi_token)],
            'resume_secrets' => $resume['execution_secrets'],
            'publish_secrets' => ['github_token' => $copy, 'git_authorization' => $authorization],
        ], $caller, $request, lockKey: OperationStore::lockKey('task_group', $group->id));
    }
}
