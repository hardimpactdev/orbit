<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\Roles\NodeRoleOperatingSystemGuard;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Captures immutable role effects using retained admission evidence; reads never install a runtime. */
final readonly class PrepareNodeRoleCommand implements OperationGatewayCommand
{
    public function __construct(private NodeRoleBaselinePlan $plan, private ManagedUserAccountResolver $accounts,
        private NodeRoleOperatingSystemGuard $operatingSystem, private OperationStore $store) {}

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['role', 'converge'],
            'properties' => ['role' => ['type' => 'string', 'enum' => array_column(RoleName::cases(), 'value')], 'converge' => ['type' => 'boolean']]];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        if (($operation->state['node_role_prepared'] ?? false) === true) {
            return ['prepared' => true];
        }
        $intent = ValidatedData::object($operation->state['role_intent'] ?? []);
        $assignment = NodeRole::query()->findOrFail(ValidatedData::integer($intent['assignment_id'] ?? null));
        $node = Node::query()->findOrFail($operation->node_id);
        if ($assignment->node_id !== $node->id || ($intent['source_node_id'] ?? null) !== $node->id
            || ($intent['role'] ?? null) !== $assignment->role->value || ($input['role'] ?? null) !== $assignment->role->value
            || ! is_bool($intent['created'] ?? null)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The role assignment differs from its retained admission.', 422);
        }
        $steps = [];
        if (($operation->state['role_noop'] ?? false) !== true) {
            $this->operatingSystem->assert($node, $assignment->role);
            $account = $this->accounts->resolve($node);
            if (($operation->state['role_first_managed'] ?? false) === true) {
                $steps[] = ['type' => 'child', 'key' => 'role-network', 'kind' => 'node.network_converge', 'node_id' => $node->id,
                    'input' => ['roleless_operator' => false], 'optional' => false];
            }
            $steps = [...$steps, ...$this->plan->converge($node, $assignment, $account)];
            $steps[] = ['type' => 'child', 'key' => 'role-footprint', 'kind' => 'node.converge', 'node_id' => $node->id,
                'input' => ['force_artifacts' => false, 'first_visit' => false], 'optional' => true];
            $steps[] = ['type' => 'child', 'key' => 'role-cli', 'kind' => 'fleet.cli_converge', 'node_id' => $node->id, 'input' => [], 'optional' => true];
            $metrics = $assignment->role === RoleName::Metrics ? null : Node::query()->whereHas('roles',
                static fn ($query) => $query->where('role', RoleName::Metrics)->where('status', LifecycleStatus::Active))->first();
            if ($metrics !== null) {
                $steps[] = ['type' => 'child', 'key' => 'role-metrics', 'kind' => 'metrics.role_converge', 'node_id' => $metrics->id, 'input' => [], 'optional' => false];
            }
        }
        $intents = [];
        foreach ($steps as $step) {
            if ($step['type'] === 'child') {
                $intents[] = ['key' => $step['key'], 'kind' => $step['kind'], 'node_id' => $step['node_id'], 'input' => $step['input']];
            }
        }
        $this->store->change($operation, ['input' => [...$operation->input, '_role_plan' => $steps],
            'state' => [...($operation->state ?? []), 'node_role_prepared' => true, 'child_intents' => $intents]]);

        return ['prepared' => true];
    }
}
