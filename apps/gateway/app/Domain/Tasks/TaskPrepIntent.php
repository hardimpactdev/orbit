<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Data\Tasks\StartTaskPrepData;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Infrastructure\Swamp\PendingSshHostKey;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Str;

/**
 * Admits `tasks:prep`: one run that prepares the spec of one command. It captures the whole intent in the admission
 * transaction of provision, so no step of the run calls the Gateway. See docs/cli/tasks/prep.mmd.
 */
final readonly class TaskPrepIntent
{
    /** The review rounds of the parent workflow `operation-task-prep`; its steps are written out, so this number is part of it. */
    public const int Rounds = 3;

    public function __construct(private TaskProvisionIntent $provision, private OperationIdempotency $idempotency) {}

    public function start(Task $group, StartTaskPrepData $data, string $caller, ?string $request): Operation
    {
        if (($replay = $this->idempotency->replay('tasks.prep')) !== null) {
            return $replay;
        }
        $prompts = TaskPrepPrompts::for($data->command, $data->brief, $data->reference);
        $group->loadMissing('project');
        $repository = GitHubRepository::fromOrigin($group->project->repository_url);
        $base = $group->project->default_branch;
        if ($repository === null || ! is_string($base) || ! GitBranchName::isValid($base)) {
            throw new ResourceOperationException('tasks.publish_failed', 'The Project requires a GitHub repository and valid default branch.', 422);
        }
        $subtask = $group->tasks()->orderBy('position')->orderBy('id')->first();
        if (! $subtask instanceof Task || ($subtask->deliverables ?? []) === []) {
            throw new ResourceOperationException('tasks.subtask_deliverables_missing', 'The group needs a subtask with a deliverable for the review agent to confirm.', 422);
        }

        return $this->provision->admit($group, null, 'tasks.prep', function (array $input, Task $group, Instance $instance, TaskVm $vm) use ($prompts, $repository, $base, $subtask, $data): array {
            $instance->loadMissing('node');
            // The guest's host key is pinned only by the VM step of the run; the run fills it into these targets.
            $ssh = app(PendingSshHostKey::class)->during($instance->node_id, fn (): array => app(SwampSshExecutionInput::class)->forNode($instance->node));
            $author = TaskPrepThreads::create($group, $subtask, $instance, 'implementer');
            // A query update: since the review mode column has a default, every subtask row carries `review_mode`, and a model save of a subtask refuses it.
            Task::query()->whereKey($subtask->id)->update(['implementer_agent_thread_id' => $author->id]);
            $reviewers = array_map(fn (int $round): AgentThread => TaskPrepThreads::create($group, $subtask, $instance, 'reviewer'), range(1, self::Rounds));
            $group->update(['reviewer_agent_thread_id' => $reviewers[0]->id]);
            $turn = fn (AgentThread $thread, string $prompt, bool $new): array => TaskPrepThreads::turn($thread, $prompt, $new, $group, $subtask, $instance, $ssh);
            $branch = 'task-'.$group->id;
            // Only the vault key names are admitted. The publish step is minutes to hours away, so it mints the token when it runs ({@see TaskGitHubTokens}).
            $copy = ['vault' => 'orbit-control-inputs', 'key' => 'task-publish-token-'.Str::uuid()];
            $authorization = ['vault' => 'orbit-control-inputs', 'key' => 'task-publish-token-'.Str::uuid()];

            return [
                'command' => $data->command, 'mode' => $group->review_mode->value, 'group_id' => $group->id, 'instance_id' => $instance->id, 'node_id' => $input['node_id'],
                'execution_secrets' => $input['execution_secrets'] ?? [],
                'provision' => array_diff_key($input, ['execution_secrets' => true]),
                'draft' => $turn($author, $prompts->draft(), true),
                'fix' => $turn($author, $prompts->fix(), false),
                'reviews' => array_map(fn (AgentThread $thread): array => $turn($thread, $prompts->review(), true), $reviewers),
                'plan' => ['cases_file' => $prompts->cases()],
                'publish' => [
                    'group_id' => $group->id, 'instance_id' => $instance->id, 'node_id' => $instance->node_id,
                    'checkout_path' => $instance->checkout_path, 'repository' => $repository->owner.'/'.$repository->name,
                    'base' => $base, 'branch' => $branch, 'remote' => 'https://github.com/'.$repository->owner.'/'.$repository->name.'.git',
                    'pull_head' => $repository->owner.':'.$branch, 'ssh_execution' => $ssh,
                    'pull_request' => ['title' => $prompts->title(), 'body' => $prompts->body(), 'draft' => true],
                ],
                'publish_secrets' => ['github_token' => $copy, 'git_authorization' => $authorization],
            ];
        }, $caller, $request);
    }
}
