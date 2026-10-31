<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Models\Instance;
use App\Models\InstanceRemovalMember;
use App\Models\Operation;
use App\Models\Project;
use InvalidArgumentException;

/** Resolves the admitted Project's existing Git access only in request memory. */
final readonly class InstanceLifecycleGitSecret implements OperationSecretSource
{
    private const array InstanceKinds = ['instance.create', 'instance.clone', 'instance.destroy', 'instance.destroy_development', 'instance.clone_candidate', 'instance.transfer_destination'];

    public function __construct(private RepositoryReadAccess $access) {}

    public function name(): string
    {
        return 'instance.lifecycle_git';
    }

    /** @return list<string> */
    public function targetTypes(): array
    {
        return ['instance'];
    }

    public function ownerId(Operation $operation): ?int
    {
        $input = $this->input($operation);
        $id = $input['project_id'] ?? null;
        if ($input === null || ! is_int($id) || ! is_int($input['instance_id'] ?? null)) {
            return null;
        }
        $instance = Instance::query()->find($input['instance_id']);
        if ($instance !== null) {
            return $instance->project_id === $id ? $id : null;
        }
        if (in_array($operation->kind, ['instance.destroy', 'instance.destroy_development'], true) && InstanceRemovalMember::query()
            ->where('instance_id', $operation->target_id)->where('project_id', $id)->where('node_id', $operation->node_id)
            ->whereNotNull('row_deleted_at')->exists()) {
            return $id;
        }

        return null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        $project = Project::query()->findOrFail($id);
        $input = $this->input($operation);
        $repository = $input['repository'] ?? null;
        try {
            $identity = is_string($repository) ? GitRepositoryIdentity::derive($repository) : null;
        } catch (InvalidArgumentException) {
            $identity = null;
        }
        if ($input === null || $parameter !== 'git_read' || $id !== $this->ownerId($operation)
            || $project->repository_identity !== ($input['repository_identity'] ?? null)
            || $identity !== $project->repository_identity) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Git reference is not owned by this lifecycle intent.', 422);
        }
        if ($operation->kind === 'instance.create' && ($operation->input['task_vm'] ?? false)) {
            return new OperationSecretValue(GitReadEnvironment::none());
        }

        return new OperationSecretValue($this->access->for($project->repository_url, $project->source_access));
    }

    /** @return array<string, mixed>|null */
    private function input(Operation $operation): ?array
    {
        return in_array($operation->kind, self::InstanceKinds, true) && $operation->target_type === 'instance'
            && $operation->target_id === ($operation->input['instance_id'] ?? null)
            ? $operation->input : null;
    }
}
