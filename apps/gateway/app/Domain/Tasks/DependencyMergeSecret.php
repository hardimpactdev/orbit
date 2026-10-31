<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\GitHub\GitHubActionsReader;
use App\Models\Operation;
use App\Models\Task;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

final readonly class DependencyMergeSecret implements OperationSecretSource
{
    public function __construct(private RepositoryPullRequestAccess $access, private DependencyBatchMergeGuard $guard, private GitHubActionsReader $reader) {}

    public function name(): string
    {
        return 'github.dependency_merge';
    }

    public function targetTypes(): array
    {
        return ['task_group'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return $operation->kind === 'tasks.dependency_merge' ? Task::topLevel()->find($operation->target_id)?->project_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($operation->kind !== 'tasks.dependency_merge' || $operation->target_type !== 'task_group' || $parameter !== 'token' || $key !== ''
            || ! DirectOperation::owns($operation) || $operation->status->terminal() || ! is_string($operation->state['swamp_run_id'] ?? null)
            || $operation->node_id !== DependencyMergeProjection::WorkerNodeId) {
            throw new ResourceOperationException('dependency_merge.secret_forbidden', 'The secret belongs to a dependency merge Project.', 403);
        }
        $group = Task::topLevel()->findOrFail($operation->target_id);
        if ($id !== $group->project_id) {
            throw new ResourceOperationException('dependency_merge.secret_forbidden', 'The secret belongs to a dependency merge Project.', 403);
        }
        $lineage = $this->guard->lineage($group, ValidatedData::integer($operation->input['publish_operation_id']), ValidatedData::string($operation->input['head_sha']));
        $expected = [...$lineage, 'node_id' => $operation->node_id];
        if (Arr::sortRecursive($expected) !== Arr::sortRecursive($operation->input)) {
            throw new ResourceOperationException('dependency_merge.lineage_changed', 'The admitted merge intent changed.', 409);
        }
        $repository = GitHubRepository::fromOrigin($group->project->repository_url);
        assert($repository !== null);

        $checks = $this->access->checksToken($repository);
        if ($checks === null || $checks === '') {
            throw new ResourceOperationException('dependency_merge.evidence_unavailable', 'The repository requires Checks read access.', 503);
        }

        return new OperationSecretValue([
            'write_token' => $this->access->squashToken($repository),
            'read_token' => $this->access->readToken($repository),
            'checks_token' => $checks,
            'actions_token' => $this->reader->token($repository),
        ]);
    }
}
