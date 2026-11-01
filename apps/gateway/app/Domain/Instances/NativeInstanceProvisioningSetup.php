<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Projects\GatewaySetupSteps;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\GatewaySetupSteps;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Reuse the Project's existing script material in the exact create root. */
final readonly class NativeInstanceProvisioningSetup implements OperationSecretSource
{
    public function __construct(private ProjectLifecycleStepStore $steps) {}

    public function name(): string
    {
        return 'instance.provisioning_setup';
    }

    public function targetTypes(): array
    {
        return ['instance'];
    }

    public function ownerId(Operation $operation): ?int
    {
        $instance = Instance::query()->find($operation->target_id);
        $plan = $operation->input['direct_provisioning'] ?? null;

        return $operation->kind === 'instance.create' && $operation->target_type === 'instance'
            && $instance !== null && $operation->node_id === $instance->node_id
            && ($operation->input['instance_id'] ?? null) === $instance->id && is_array($plan)
            && ($plan['checkout'] ?? null) === $instance->checkout_path
            && ($plan['project_id'] ?? null) === $instance->project_id ? $instance->id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($id !== $this->ownerId($operation) || $key !== '' || $parameter !== 'setup_programs') {
            $this->refuse();
        }
        $instance = Instance::query()->with('project')->findOrFail($id);
        $steps = $this->steps->ordered($instance->project, LifecyclePhase::Setup);
        $identities = array_map(static fn ($step): array => ['name' => $step->name,
            'command_digest' => hash('sha256', $step->command), 'timeout_seconds' => $step->timeoutSeconds], $steps);
        $plan = ValidatedData::object($operation->input['direct_provisioning']);
        $setup = ValidatedData::object($plan['setup'] ?? []);
        if (($setup['steps'] ?? null) !== $identities) {
            $this->refuse();
        }

        return new OperationSecretValue(['steps' => array_map(static fn ($step): array => [
            'name' => $step->name, 'timeout_seconds' => $step->timeoutSeconds,
            'command' => GatewaySetupSteps::owns($step->command) ? GatewaySetupSteps::script($step->command) : $step->command], $steps)]);
    }

    private function refuse(): never
    {
        throw new ResourceOperationException('operation.execution_forbidden', 'The setup program does not match the admitted create root.', 422);
    }
}
