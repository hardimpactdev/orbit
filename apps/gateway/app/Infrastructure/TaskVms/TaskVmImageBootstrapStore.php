<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Operations\OperationSecretOutputStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\UpCloudTaskVmImageBuild;

/** Stores native-generated host keys in the existing encrypted attempt record. */
final readonly class TaskVmImageBootstrapStore implements OperationSecretOutputStore
{
    public function name(): string
    {
        return 'task_vm.image_bootstrap';
    }

    public function targetTypes(): array
    {
        return ['task_vm_upcloud_image'];
    }

    public function ownerId(Operation $operation): ?int
    {
        if ($operation->kind !== 'task_vm.upcloud_image_build' || $operation->target_type !== 'task_vm_upcloud_image') {
            return null;
        }
        $build = UpCloudTaskVmImageBuild::query()->find($operation->target_id);

        return ($build?->intent['node_id'] ?? null) === $operation->node_id && ($build?->intent['build_id'] ?? null) === $build?->id ? $build?->id : null;
    }

    public function store(Operation $operation, int $ownerId, #[\SensitiveParameter] mixed $value): void
    {
        if ($ownerId !== $this->ownerId($operation) || ! is_array($value) || array_keys($value) !== ['builder', 'smoke']) {
            $this->refuse();
        }
        foreach ($value as $pair) {
            if (! is_array($pair) || array_keys($pair) !== ['private', 'public'] || ! is_string($pair['private']) || ! is_string($pair['public'])
                || strlen($pair['private']) > 16384 || strlen($pair['public']) > 1024
                || preg_match('/\A-----BEGIN OPENSSH PRIVATE KEY-----\n[A-Za-z0-9+\/=\n]+\n-----END OPENSSH PRIVATE KEY-----\n?\z/D', $pair['private']) !== 1
                || preg_match('/\Assh-ed25519 [A-Za-z0-9+\/]+={0,2}(?: [!-~]+)?\z/D', $pair['public']) !== 1) {
                $this->refuse();
            }
        }
        $build = UpCloudTaskVmImageBuild::query()->findOrFail($ownerId);
        if ($build->bootstrap !== null && $build->bootstrap !== $value) {
            $this->refuse();
        }
        $build->forceFill(['bootstrap' => $value])->save();
    }

    private function refuse(): never
    {
        throw new ResourceOperationException('operation.secret_output_unavailable', 'Host keys must belong to the admitted image attempt and cannot be replaced.', 409);
    }
}
