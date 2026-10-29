<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Task;

final readonly class DependencyMergeSecret implements OperationSecretSource
{
    public function __construct(private RepositoryPullRequestAccess $access) {}

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
        if ($operation->kind !== 'tasks.dependency_merge' || $operation->target_type !== 'task_group') {
            throw new ResourceOperationException('dependency_merge.secret_forbidden', 'The secret belongs to a dependency merge Project.', 403);
        }
        $group = Task::topLevel()->findOrFail($operation->target_id);
        if ($id !== $group->project_id) {
            throw new ResourceOperationException('dependency_merge.secret_forbidden', 'The secret belongs to a dependency merge Project.', 403);
        }
        $repository = GitHubRepository::fromOrigin($group->project->repository_url);
        assert($repository !== null);

        return new OperationSecretValue($this->access->squashToken($repository));
    }
}
