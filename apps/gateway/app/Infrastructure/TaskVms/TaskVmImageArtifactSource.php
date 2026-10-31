<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Operations\OperationArtifact;
use App\Domain\Operations\OperationArtifactSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\TaskVmImageBuild;
use App\Support\ValidatedData;

final readonly class TaskVmImageArtifactSource implements OperationArtifactSource
{
    public function name(): string
    {
        return 'task_vm.image_artifact';
    }

    public function targetTypes(): array
    {
        return ['task_vm_image'];
    }

    public function ownerId(Operation $operation): ?int
    {
        if ($operation->kind !== 'task_vm.image_build' || $operation->target_type !== 'task_vm_image') {
            return null;
        }
        $build = TaskVmImageBuild::query()->find($operation->target_id);

        return $build !== null && $build->id === ($operation->input['build_id'] ?? null) && $build->host_node_id === $operation->node_id
            && $build->attempt === ($operation->input['attempt'] ?? null) && $build->cleanup_started_at === null ? $build->id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        return new OperationSecretValue($this->artifact($operation, $id)->reference($operation->id,
            ValidatedData::string($operation->state['swamp_run_id'] ?? null), $parameter));
    }

    public function artifact(Operation $operation, int $ownerId): OperationArtifact
    {
        $input = $operation->input['pi_artifact'] ?? null;
        $root = config()->string('orbit.home').'/sandbox-artifacts/';
        if ($ownerId !== $this->ownerId($operation) || ! is_array($input) || ! is_string($input['path'] ?? null)
            || ! str_starts_with($input['path'], $root) || preg_match('/\A[a-zA-Z0-9._-]+\z/D', substr($input['path'], strlen($root))) !== 1
            || ! is_string($input['sha256'] ?? null)) {
            throw new ResourceOperationException('operation.artifact_unavailable', 'The artifact does not belong to this image attempt.', 409);
        }

        return new OperationArtifact($input['path'], $input['sha256']);
    }
}
