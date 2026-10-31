<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Routes\RouteWithdrawalArtifacts;
use App\Domain\Routes\SharedPhpPoolInput;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Current whole-Node PHP render, hydrated inside the native publication lock. */
final readonly class InstanceDestroyPhpSecret implements OperationSecretSource
{
    public function name(): string
    {
        return 'instance.destroy_php';
    }

    public function targetTypes(): array
    {
        return ['instance'];
    }

    public function ownerId(Operation $operation): ?int
    {
        $target = Instance::query()->with('node')->find($operation->target_id);

        return $operation->kind === 'instance.destroy_development' && $operation->target_type === 'instance'
            && $target !== null && $target->id === ($operation->input['instance_id'] ?? null)
            && $target->node_id === $operation->node_id && $target->status === InstanceState::Removing
            && $target->placedOnAppDev() ? $target->id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($id !== $this->ownerId($operation) || $key !== '' || $parameter !== 'php') {
            throw new ResourceOperationException('operation.execution_forbidden', 'PHP withdrawal requires its exact removing Instance and Node.', 422);
        }
        $target = Instance::query()->with('node')->findOrFail($id);
        $detail = ValidatedData::object($operation->input['direct_destroy']);
        if ($target->node->user !== $detail['node_user']) {
            throw new ResourceOperationException('instance.removal_conflict', 'The admitted Node account changed.', 409);
        }
        $value = ['node_id' => $target->node_id, 'user' => ValidatedData::string($detail['node_user']), 'agent_sha256' => ValidatedData::string($detail['agent_sha256']),
            ...app(SharedPhpPoolInput::class)->forAccount($target->node, new ManagedUserAccount(RouteWithdrawalArtifacts::User, RouteWithdrawalArtifacts::Group, RouteWithdrawalArtifacts::Home))];

        return new OperationSecretValue($value, [json_encode($value, JSON_THROW_ON_ERROR)]);
    }
}
