<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Data\Tasks\StartTaskPrepFixData;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmProviderWorkflowInput;
use App\Domain\TaskVms\TaskVmResumeIntent;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Str;

/**
 * Admits `task:prep-fix`: one run that applies the review of the owner to a parked prep group. The shared task admission holds
 * the lock, the busy check and the replay; this class checks that the group is a parked prep group and captures the whole
 * intent, so no step of the run calls the Gateway but the push token mint. See docs/cli/tasks/prep-fix.mmd.
 */
final readonly class TaskPrepFixIntent
{
    /** The review rounds of the parent workflow `operation-task-prep-fix`; its steps are written out, so this number is part of it. */
    public const int Rounds = 2;

    public function __construct(private TaskOperations $operations, private TaskVmResumeIntent $resume) {}

    public function start(Task $group, StartTaskPrepFixData $data, string $caller, ?string $request): Operation
    {
        // The capture checks the group; the shared admission needs a validate step only for the checks it makes itself.
        return $this->operations->start($group, 'tasks.prep_fix', [], static function (Task $group): void {}, $caller, $request, fn (Task $group): array => $this->capture($group, $data));
    }

    /**
     * The succeeded prep run, the task VM and the workspace of a group that can take corrections.
     *
     * @return array{Operation, TaskVm, Instance}
     */
    private function context(Task $group): array
    {
        $group->loadMissing('project', 'taskable');
        $instance = $group->taskable;
        $vm = TaskVm::forGroup($group);
        if (! $instance instanceof Instance || $instance->task_workspace_owner_id !== $group->id || ! $vm instanceof TaskVm || $vm->node_id !== $instance->node_id || $instance->checkout_path === '') {
            throw new ResourceOperationException('tasks.no_workspace', 'The group requires its owned task VM workspace.', 409);
        }
        $prep = Operation::query()->where('kind', 'tasks.prep')->where('target_type', 'task_group')->where('target_id', $group->id)
            ->where('status', OperationStatus::Succeeded)->latest('id')->first();
        if (! $prep instanceof Operation || ! in_array($vm->state, [TaskVmState::Parked, TaskVmState::Ready], true) || ! $group->pr_url || $group->watched_pr_state !== 'open'
            || ! is_string($vm->pi_token) || strlen($vm->pi_token) < 32) {
            throw new ResourceOperationException('tasks.prep_fix_refused', 'The corrections need a prep group whose run succeeded, with its task VM parked or running, its Pi credential and its pull request open.', 409);
        }

        return [$prep, $vm, $instance];
    }

    /** @return array<string,mixed> */
    private function capture(Task $group, StartTaskPrepFixData $data): array
    {
        [$prep, $vm, $instance] = $this->context($group);
        $earlier = ValidatedData::object($prep->input);
        $author = AgentThread::query()->where('task_group_id', $group->id)->where('role', 'implementer')->find(ValidatedData::integer(ValidatedData::object($earlier['draft'])['thread_id']));
        $subtask = $group->tasks()->orderBy('position')->orderBy('id')->first();
        $repository = GitHubRepository::fromOrigin($group->project->repository_url);
        if (! $author instanceof AgentThread || ! $subtask instanceof Task || $repository === null) {
            throw new ResourceOperationException('tasks.prep_fix_refused', 'The group has no author thread, subtask or GitHub repository to continue from.', 409);
        }
        $prompts = TaskPrepPrompts::for(ValidatedData::string($earlier['command']));
        $resume = TaskVmProviderWorkflowInput::envelope($this->resume->capture($vm));
        $instance->loadMissing('node');
        $ssh = app(SwampSshExecutionInput::class)->forNode($instance->node);
        $reviewers = array_map(static fn (int $round): AgentThread => TaskPrepThreads::create($group, $subtask, $instance, 'reviewer'), range(1, self::Rounds));
        $turn = static fn (AgentThread $thread, string $prompt, bool $new): array => TaskPrepThreads::turn($thread, $prompt, $new, $group, $subtask, $instance, $ssh);

        return [
            'command' => $prompts->command, 'group_id' => $group->id, 'instance_id' => $instance->id, 'node_id' => $resume['intent']['node_id'],
            'resume' => $resume['intent'],
            // The author thread continues its session; the Pi server answers it again after the resume.
            'author' => $turn($author, $prompts->corrections($data->corrections), false),
            'fix' => $turn($author, $prompts->fixCorrections(), false),
            'reviews' => array_map(static fn (AgentThread $thread): array => $turn($thread, $prompts->reviewCorrections($data->corrections), true), $reviewers),
            'plan' => ['cases_file' => $prompts->cases()],
            'publish' => TaskPublishIntent::destination($group, $instance, $repository, $ssh),
            // Only vault key names are admitted. The Pi token is written when the run starts ({@see TaskPrepFixProjection::workflowInput()}),
            // and the push step mints its token when it runs ({@see TaskGitHubTokens}).
            'execution_secrets' => ['pi_token' => ['vault' => 'orbit-control-inputs', 'key' => 'task-vm-pi-token-'.$vm->id]],
            'resume_secrets' => $resume['execution_secrets'],
            'publish_secrets' => ['github_token' => ['vault' => 'orbit-control-inputs', 'key' => 'task-publish-token-'.Str::uuid()],
                'git_authorization' => ['vault' => 'orbit-control-inputs', 'key' => 'task-publish-token-'.Str::uuid()]],
        ];
    }
}
