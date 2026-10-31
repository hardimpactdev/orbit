<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationPublicView;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Illuminate\Support\Arr;

/** The admitted candidate reads source intent; only the destination projects results. */
final readonly class InstanceCloneProjection implements DirectOperationProjection, DirectOperationPublicView
{
    public function __construct(private NativeInstanceProvisioningProjection $provisioning) {}

    public function input(Operation $operation): array
    {
        return $this->provisioning->input($operation);
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return $this->provisioning->apply($operation, $data);
    }

    public function assertPublicParticipant(Operation $operation, int $callerNodeId): void
    {
        if ($operation->kind !== 'instance.clone'
            || ($operation->input['candidate_node_id'] ?? null) !== $callerNodeId
            || data_get($operation->input, 'direct_provisioning.candidate.node_id') !== $callerNodeId) {
            throw new ResourceOperationException('operation.executor_forbidden', 'Only the admitted clone candidate may read this source intent.', 403);
        }
    }

    public function publicInput(Operation $operation, int $callerNodeId): array
    {
        $this->assertPublicParticipant($operation, $callerNodeId);

        return Arr::only($this->input($operation), ['direct_provisioning', 'git_read']);
    }
}
