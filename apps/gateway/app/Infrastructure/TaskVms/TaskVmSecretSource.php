<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\UpCloudTaskVmImageBuild;

/** Tagged family sources. Ownership checks never read credentials. */
final readonly class TaskVmSecretSource implements OperationSecretSource
{
    public function __construct(private string $source) {}

    public function name(): string
    {
        return $this->source;
    }

    /** @return list<string> */
    public function targetTypes(): array
    {
        return match ($this->source) {
            'upcloud.api' => ['task_vm_upcloud_image'],
            'task_vm.image_bootstrap' => ['task_vm_upcloud_image'],
            default => ['task_vm'],
        };
    }

    public function ownerId(Operation $operation): ?int
    {
        if (! in_array($operation->target_type, $this->targetTypes(), true)) {
            return null;
        }
        $id = $operation->target_id;
        if ($operation->target_type === 'task_vm_upcloud_image') {
            $build = UpCloudTaskVmImageBuild::query()->find($id);
            if ($build?->intent === null || $build->intent['node_id'] !== $operation->node_id) {
                return null;
            }
        }

        return $id;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($this->ownerId($operation) !== $id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The secret reference is not owned by this target.', 422);
        }
        if ($this->source === 'upcloud.api') {
            return new OperationSecretValue(app(UpCloudApi::class)->token());
        }
        if ($this->source === 'task_vm.image_bootstrap') {
            return new OperationSecretValue(UpCloudTaskVmImageBuild::query()->findOrFail($id)->bootstrap);
        }
        throw new ResourceOperationException('operation.secret_unavailable', 'The secret source is unavailable.', 409);
    }
}
