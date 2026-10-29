<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\Environment\EnvironmentSyncMaterial;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

final readonly class StartInstanceEnvironmentSyncAction
{
    public function __construct(private StartOperationAction $operations, private EnvironmentSyncMaterial $material) {}

    /** @param array{key:string,kind:string,node_id:int|null,input:array<string,mixed>} $intent */
    public function execute(Operation $parent, array $intent): Operation
    {
        $id = $intent['input']['instance_id'] ?? null;
        if (! $parent->exists || $intent['kind'] !== 'instance.sync_environment' || ! is_int($id) || $id < 1) {
            throw new ResourceOperationException('operation.child_forbidden', 'The environment child requires its owning Instance.', 422);
        }
        $candidate = new Operation()->forceFill(['kind' => $intent['kind'], 'target_type' => 'instance', 'target_id' => $id,
            'node_id' => $intent['node_id'], 'input' => $intent['input'], 'parent_operation_id' => $parent->id, 'child_key' => $intent['key']]);
        $this->material->context($candidate);

        return $this->operations->execute($intent['kind'], 'instance', $id, $intent['node_id'], $intent['input'], $parent->triggered_by, null);
    }
}
