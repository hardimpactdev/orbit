<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Actions\Operations\StartOperationAction;
use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubTokenGrant;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\DependencyBatchMergeGuard;
use App\Domain\Tasks\DependencyMergeProjection;
use App\Domain\Tasks\TaskExecutionLock;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Operation;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class StartDependencyBatchMergeAction
{
    public function __construct(private DependencyBatchMergeGuard $guard, private StartOperationAction $operations,
        private RequireTasksExtensionAction $extension, private OperationIdempotency $idempotency) {}

    public function execute(Task $group, int $publish, string $head, string $caller, string $request): Operation
    {
        $this->extension->execute();
        if (($existing = $this->idempotency->replay('tasks.dependency_merge')) !== null) {
            return $existing;
        }

        return app(TaskExecutionLock::class)->synchronized($group->id, function () use ($group, $publish, $head, $caller, $request): Operation {
            if (($existing = $this->idempotency->replay('tasks.dependency_merge')) !== null) {
                return $existing;
            }
            if (Operation::query()->where('target_type', 'task_group')->where('target_id', $group->id)
                ->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
                throw new ResourceOperationException('operation.target_busy', 'Another Task Operation owns this group.', 409);
            }
            $intent = [...$this->guard->lineage($group, $publish, $head), 'node_id' => DependencyMergeProjection::WorkerNodeId];
            $credentials = app(GitHubAppStore::class)->credentials();
            if ($credentials === null) {
                throw new ResourceOperationException('github.not_configured', 'Configure the GitHub App before merging a dependency batch.', 409);
            }
            $secrets = [];
            $tokens = [];
            foreach (['write_token' => ['contents' => 'write', 'pull_requests' => 'write'],
                'read_token' => ['contents' => 'read', 'pull_requests' => 'read', 'checks' => 'read', 'actions' => 'read']] as $name => $permissions) {
                $tokens[$name] = app(GitHubApi::class)->scopedRepositoryToken($credentials, GitHubTokenGrant::from(['repository' => $intent['repository'], 'permissions' => $permissions]));
                $secrets[$name] = ['vault' => 'orbit-control-inputs', 'key' => 'dependency-merge-'.str_replace('_token', '', $name).'-'.Str::uuid()];
            }

            return DB::transaction(function () use ($group, $intent, $secrets, $tokens, $caller, $request): Operation {
                Task::topLevel()->whereKey($group->id)->lockForUpdate()->firstOrFail();
                $operation = $this->operations->execute('tasks.dependency_merge', 'task_group', $group->id,
                    DependencyMergeProjection::WorkerNodeId, [...$intent, 'execution_secrets' => $secrets], $caller, $request,
                    lockKey: OperationStore::lockKey('task_group', $group->id));
                if ($operation->wasRecentlyCreated) {
                    foreach ($secrets as $name => $descriptor) {
                        app(SwampCommandStep::class)->executionCopy($descriptor['vault'], $descriptor['key'], $tokens[$name]->reveal());
                    }
                }

                return $operation;
            });
        });
    }
}
