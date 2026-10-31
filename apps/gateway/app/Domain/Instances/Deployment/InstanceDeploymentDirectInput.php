<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Operations\OperationChildIntent;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Pure admission validation for native deployment DAG steps; no effects or child plan. */
final readonly class InstanceDeploymentDirectInput
{
    public function __construct(private InstanceDeploymentIntent $intents, private InstanceDeploymentGuards $guards) {}

    /** @return array<string, mixed> */
    public function input(Operation $operation): array
    {
        $instance = Instance::query()->with(['project', 'node.roles'])->findOrFail($operation->target_id);
        if ($operation->kind !== 'instance.deploy' || $operation->target_type !== 'instance'
            || $operation->node_id !== $instance->node_id || ($operation->input['instance_id'] ?? null) !== $instance->id
            || ! is_array($operation->input['deployment'] ?? null)) {
            throw $this->changed();
        }
        $admitted = ValidatedData::object($operation->input['deployment']);
        if (($admitted['variant'] ?? null) !== 'development' || ($admitted['projection_route_id'] ?? null) !== null) {
            throw $this->changed();
        }
        $current = $this->intents->snapshot($instance);
        if (! OperationChildIntent::same($admitted, $current)) {
            throw $this->changed();
        }
        $user = $admitted['node_user'] ?? null;
        if (! is_string($user) || $user === 'root' || preg_match('/\A[a-z_][a-z0-9_-]{0,63}\z/D', $user) !== 1) {
            throw $this->changed();
        }
        $this->guards->assert($operation, $instance);

        return $this->admitted($operation);
    }

    /** Immutable native input also remains readable after terminal completion.
     * @return array<string,mixed>
     */
    public function admitted(Operation $operation): array
    {
        $admitted = ValidatedData::object($operation->input['deployment']);
        $pin = $operation->input['agent_sha256'] ?? null;
        if (! is_string($pin) || preg_match('/\A[a-f0-9]{64}\z/D', $pin) !== 1) {
            throw $this->changed();
        }

        return ['instance_id' => $operation->target_id, 'project_id' => ValidatedData::integer($admitted['project_id']),
            'agent_sha256' => $pin, 'deployment' => $admitted, 'commit' => InstanceDeploymentIntent::commit($operation),
            'release_name' => InstanceDeploymentIntent::releaseName($operation)];
    }

    private function changed(): ResourceOperationException
    {
        return new ResourceOperationException('deployment.identity_changed', 'The deployment target or configuration changed after admission.', 409);
    }
}
