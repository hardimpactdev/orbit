<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Actions\Operations\StartOperationAction;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubTokenGrant;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\DependencyMergeProjection;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskOperations;
use App\Domain\Tasks\TaskPullRequestEvidence;
use App\Domain\Tasks\TaskPullRequestTarget;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Operation;
use App\Models\Task;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admits the ready step and the merge step of a task pull request. The Gateway reads GitHub with read-only tokens,
 * mints one short-lived write token for the step and records the intent. Swamp calls GitHub with that token.
 */
final readonly class StartTaskPullRequestStepAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private StartOperationAction $operations,
        private OperationIdempotency $idempotency, private TaskOperations $groups, private TaskPullRequestEvidence $evidence) {}

    public function ready(Task $group, string $head, string $mode, string $caller, string $request): Operation
    {
        return $this->admit(OperationKinds::TasksPrReady, $group, ['pull_requests' => 'write'], 'pr-ready', $caller, $request,
            fn (): TaskPullRequestTarget => $this->evidence->ready($group, $head, $mode),
            ['head_sha' => $head, 'mode' => $mode, 'reviewer' => TaskPullRequestEvidence::reviewer()]);
    }

    public function merge(Task $group, string $head, string $caller, string $request): Operation
    {
        return $this->admit(OperationKinds::TasksPrMerge, $group, ['contents' => 'write', 'pull_requests' => 'write'], 'pr-merge', $caller, $request,
            fn (): TaskPullRequestTarget => $this->evidence->merge($group, $head), ['head_sha' => $head]);
    }

    /**
     * @param  array<string, string>  $permissions
     * @param  Closure(): TaskPullRequestTarget  $read
     * @param  array<string, mixed>  $extra
     */
    private function admit(string $kind, Task $group, array $permissions, string $prefix, string $caller, string $request, Closure $read, array $extra): Operation
    {
        $this->extension->execute();
        if (($existing = $this->idempotency->replay($kind)) !== null) {
            return $existing;
        }

        return app(TaskExecutionLock::class)->synchronized($group->id, function () use ($kind, $group, $permissions, $prefix, $caller, $request, $read, $extra): Operation {
            if (($existing = $this->idempotency->replay($kind)) !== null) {
                return $existing;
            }
            $group->refresh();
            $this->groups->requireOpenExternal($group);
            if (Operation::query()->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id)
                ->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
                throw new ResourceOperationException('operation.target_busy', 'Another Task Operation owns this group.', 409);
            }
            $target = $read();
            $intent = [...$target->intent($group), ...$extra, 'node_id' => DependencyMergeProjection::WorkerNodeId];
            $credentials = app(GitHubAppStore::class)->credentials()
                ?? throw new ResourceOperationException('github.not_configured', 'Configure the GitHub App before merging a task pull request.', 409);
            try {
                $token = app(GitHubApi::class)->scopedRepositoryToken($credentials, GitHubTokenGrant::from(['repository' => $intent['repository'], 'permissions' => $permissions]));
            } catch (GitHubApiException $exception) {
                report($exception);
                throw new ResourceOperationException('tasks_pr.github_unreadable', 'The Gateway could not mint a GitHub token for the step.', 502);
            }
            $secrets = ['write_token' => ['vault' => 'orbit-control-inputs', 'key' => $prefix.'-write-'.Str::uuid()]];

            return DB::transaction(function () use ($kind, $group, $intent, $secrets, $token, $caller, $request): Operation {
                Task::topLevel()->whereKey($group->id)->lockForUpdate()->firstOrFail();
                $operation = $this->operations->execute($kind, OperationKinds::TaskGroupTarget, $group->id,
                    DependencyMergeProjection::WorkerNodeId, [...$intent, 'execution_secrets' => $secrets], $caller, $request,
                    lockKey: OperationStore::lockKey(OperationKinds::TaskGroupTarget, $group->id));
                if ($operation->wasRecentlyCreated) {
                    app(SwampCommandStep::class)->executionCopy($secrets['write_token']['vault'], $secrets['write_token']['key'], $token->reveal());
                }

                return $operation;
            });
        });
    }
}
