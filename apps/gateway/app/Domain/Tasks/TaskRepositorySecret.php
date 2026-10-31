<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryPullRequestAccess;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;

/** Existing GitHub App installation tokens, scoped to the admitted Task Project. */
final readonly class TaskRepositorySecret implements OperationSecretSource
{
    public function __construct(private RepositoryPullRequestAccess $access) {}

    public function name(): string
    {
        return 'github.task_repository';
    }

    public function targetTypes(): array
    {
        return ['task_group'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return $operation->kind === 'tasks.check' ? Task::topLevel()->find($operation->target_id)?->project_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        $group = Task::topLevel()->with('project', 'taskable')->find($operation->target_id);
        $instance = $group?->taskable;
        $repository = $group === null ? null : GitHubRepository::fromOrigin($group->project->repository_url);
        if ($this->ownerId($operation) !== $id || $key !== '' || $parameter !== 'credentials' || $operation->target_type !== 'task_group'
            || ! DirectOperation::owns($operation) || $operation->status->terminal() || ! is_string($operation->state['swamp_run_id'] ?? null)
            || ! $instance instanceof Instance || $instance->node_id !== $operation->node_id || $repository === null
            || $operation->input['repository'] !== $repository->owner.'/'.$repository->name) {
            throw new ResourceOperationException('tasks.repository_token_forbidden', 'The credential belongs to the admitted Task repository.', 403);
        }
        $checks = $this->access->checksToken($repository);
        if ($checks === null) {
            throw new ResourceOperationException('tasks.handoff_ci_unavailable', 'The repository requires Checks read access.', 503);
        }

        return new OperationSecretValue(['repository' => $repository->owner.'/'.$repository->name,
            'write_token' => $this->access->token($repository), 'checks_token' => $checks, 'read_token' => $this->access->readToken($repository)]);
    }
}
