<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Validate the admitted identity before terminal projection. */
final readonly class InstanceLifecycleState
{
    public function instance(Operation $operation): Instance
    {
        $instance = Instance::query()->with(['project', 'node'])->findOrFail(ValidatedData::integer($operation->input['instance_id']));
        $nodeId = $operation->node_id;
        $checkout = $operation->input['checkout'] ?? null;
        if ($instance->node_id !== $nodeId
            || $checkout !== null && $instance->checkout_path !== $checkout
            || isset($operation->input['project_id']) && $instance->project_id !== $operation->input['project_id']
            || isset($operation->input['repository_identity']) && $instance->project->repository_identity !== $operation->input['repository_identity']
            || $operation->kind === 'instance.clone' && ($instance->clone_candidate_id !== $operation->input['candidate_id']
                || $instance->production_user !== $operation->input['production_user']
                || $instance->production_home !== $operation->input['production_home'])) {
            throw new ResourceOperationException('instance.lifecycle_conflict', 'The admitted Instance placement or repository changed.', 409);
        }

        return $instance;
    }
}
