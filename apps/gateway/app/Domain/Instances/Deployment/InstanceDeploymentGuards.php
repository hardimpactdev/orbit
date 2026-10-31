<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Actions\Instances\InstanceSmokeControlAction;
use App\Actions\Instances\InstanceSmokeRecoveryAction;
use App\Data\Instances\InstanceSmokeRollbackData;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Node;
use App\Models\Operation;
use Closure;

/** Fresh admission checks at the effect boundary; contains no deployment execution. */
final readonly class InstanceDeploymentGuards
{
    public function __construct(
        private InstanceEnvironmentOperationLock $locks,
        private AutomaticDeployPolicyWriter $writer,
        private InstanceSmokeRecoveryAction $recovery,
        private InstanceSmokeControlAction $control,
    ) {}

    /** @template T
     * @param  Closure(Instance): T  $effect
     * @return T
     */
    public function around(Operation $operation, Closure $effect): mixed
    {
        return $this->locks->run([$operation->target_id], function () use ($operation, $effect): mixed {
            $instance = Instance::query()->with(['node.roles', 'project'])->find($operation->target_id);
            if (! $instance instanceof Instance || $instance->node_id !== $operation->node_id) {
                throw new ResourceOperationException('operation.target_missing', 'The admitted Instance placement is unavailable.', 409);
            }
            $this->assert($this->deploymentParent($operation), $instance);

            return $effect($instance);
        });
    }

    /** A deployment child inherits its parent's smoke/policy guard from server-stamped ancestry. */
    public function deploymentParent(Operation $operation): Operation
    {
        $current = $operation;
        for ($depth = 0; $depth < 8; $depth++) {
            if (in_array($current->kind, ['instance.deploy', 'instance.rollback'], true)) {
                if ($current->target_type !== 'instance' || $current->target_id !== $operation->target_id || $current->node_id !== $operation->node_id) {
                    throw new ResourceOperationException('operation.execution_forbidden', 'The deployment parent does not own this effect.', 422);
                }

                return $current;
            }
            $parentId = $current->getAttribute('parent_operation_id');
            if (! is_int($parentId)) {
                return $operation;
            }
            $current = Operation::query()->findOrFail($parentId);
        }
        throw new ResourceOperationException('operation.execution_forbidden', 'The deployment ancestry is invalid.', 422);
    }

    public function assert(Operation $operation, Instance $instance): void
    {
        if (isset($operation->input['smoke_hold_key'])) {
            $this->control->guardRedeploy($instance, $operation);

            return;
        }
        if ($operation->kind === OperationKinds::InstanceRollback) {
            if (isset($operation->input['failure_key'])) {
                $this->recovery->guard($instance, Node::query()->findOrFail($operation->callerNodeId()),
                    InstanceSmokeRollbackData::from($operation->input),
                    InstanceDeploymentIntent::provenActivation($operation) !== null);
            }

            return;
        }
        if (! in_array($operation->triggered_by, AutomaticDeployHistory::Triggers, true)
            || (! $instance->placedOnAppProd() && ! isset($operation->input['caller_node_id']))) {
            return;
        }
        $this->writer->assertSwamp($instance);
        if ($instance->placedOnAppDev()) {
            return;
        }
        $history = new AutomaticDeployHistory($instance);
        if ($history->pausedBy() instanceof InstanceDeployment) {
            throw new ResourceOperationException(AutomaticDeployHistory::Paused, 'A manual deployment or rollback pauses automatic deployment.', 409);
        }
        $commit = InstanceDeploymentIntent::commit($operation);
        if ($commit !== null && in_array($commit, [...$history->rolledAway(), ...AutomaticDeployState::of($instance)->blocked()], true)) {
            throw new ResourceOperationException('deployment.commit_blocked', 'The admitted commit is blocked.', 409);
        }
    }
}
