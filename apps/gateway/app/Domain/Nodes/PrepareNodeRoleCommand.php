<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
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
        $role = ['type' => 'string', 'enum' => array_column(RoleName::cases(), 'value')];

        return ['oneOf' => [
            ['type' => 'object', 'additionalProperties' => false, 'required' => ['role', 'converge'],
                'properties' => ['role' => $role, 'converge' => ['type' => 'boolean']]],
            ['type' => 'object', 'additionalProperties' => false, 'required' => ['assignment_id'],
                'properties' => ['assignment_id' => ['type' => 'integer', 'minimum' => 1]]],
            ['type' => 'object', 'additionalProperties' => false, 'required' => ['role', 'purge_data', 'offline'],
                'properties' => ['role' => $role, 'purge_data' => ['type' => 'boolean'], 'offline' => ['type' => 'boolean']]],
        ]];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        if (($operation->state['node_role_prepared'] ?? false) === true) {
            return ['prepared' => true];
        }
        $intent = ValidatedData::object($operation->state['role_intent'] ?? []);
        $assignment = NodeRole::query()->findOrFail(ValidatedData::integer($intent['assignment_id'] ?? null));
        $node = app(NodeEnrollmentContext::class)->node($operation);
        if (in_array($operation->kind, ['node.role_relocate', 'node.role_relocate_source', 'node.role_relocate_target'], true)) {
            return $this->relocation($operation, $input, $intent, $assignment, $node);
        }
        if ($assignment->node_id !== $node->id || ($intent['source_node_id'] ?? null) !== $node->id
            || ($intent['role'] ?? null) !== $assignment->role->value || ($operation->kind !== 'node.role_converge' && ($input['role'] ?? null) !== $assignment->role->value)
            || ($operation->kind === 'node.role_converge' && ($input['assignment_id'] ?? null) !== $assignment->id)
            || ! is_bool($intent['created'] ?? null)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The role assignment differs from its retained admission.', 422);
        }
        $steps = [];
        if ($operation->kind === 'node.role_remove') {
            $steps = $this->plan->remove($node, $assignment, ($input['purge_data'] ?? false) === true, ($operation->state['role_offline'] ?? false) === true);
            if (($operation->state['role_owned_by_parent'] ?? false) !== true && ($operation->state['role_offline'] ?? false) !== true
                && ! $node->roles()->whereKeyNot($assignment->id)->exists()) {
                $steps[] = ['type' => 'run', 'key' => 'role-public-ssh', 'transport' => 'agent', 'name' => 'firewall.rules',
                    'input' => app(NodeRoleFirewallInput::class)->publicSsh($node), 'result' => ['matches' => true], 'optional' => false];
            }
        } elseif (($operation->state['role_noop'] ?? false) !== true) {
            $this->operatingSystem->assert($node, $assignment->role);
            $account = $this->accounts->resolve($node);
            if (($operation->state['role_first_managed'] ?? false) === true) {
                $steps[] = ['type' => 'child', 'key' => 'role-network', 'kind' => 'node.network_converge', 'node_id' => $node->id,
                    'input' => ['roleless_operator' => false], 'optional' => false];
            }
            $steps = [...$steps, ...$this->plan->converge($node, $assignment, $account)];
            if (($operation->state['role_first_managed'] ?? false) === true) {
                if (! is_string($node->wireguard_public_key) || $node->ssh_host_fingerprint === null) {
                    throw new ResourceOperationException('vpn.peer_key_missing', 'The first role requires the recorded membership peer.', 409);
                }
                $steps[] = ['type' => 'run', 'key' => 'role-network-commit', 'transport' => 'agent', 'name' => 'wireguard.commit-peer',
                    'input' => ['public_key' => $node->wireguard_public_key], 'result' => [], 'optional' => false];

            }
            if ($node->status === LifecycleStatus::Active) {
                $steps[] = ['type' => 'child', 'key' => 'role-footprint', 'kind' => 'node.converge', 'node_id' => $node->id,
                    'input' => ['force_artifacts' => false, 'first_visit' => false], 'optional' => true];
                $steps[] = ['type' => 'child', 'key' => 'role-cli', 'kind' => 'fleet.cli_converge', 'node_id' => $node->id, 'input' => [], 'optional' => true];
            }
            $metrics = $assignment->role === RoleName::Metrics ? null : Node::query()->whereHas('roles',
                static fn ($query) => $query->where('role', RoleName::Metrics)->where('status', LifecycleStatus::Active))->first();
            if ($metrics !== null) {
                throw new ResourceOperationException('metrics.native_composition_required', 'This parent requires Metrics stages in the same native run.', 409);
            }
        }

        return $this->persist($operation, $steps);
    }

    /** @param array<string,mixed> $input
     * @param  array<string,mixed>  $intent
     * @return array<string,mixed>
     */
    private function relocation(Operation $operation, array $input, array $intent, NodeRole $assignment, Node $node): array
    {
        $sourceId = ValidatedData::integer($intent['source_node_id'] ?? null);
        $targetId = ValidatedData::integer($intent['target_node_id'] ?? null);
        $role = RoleName::from(ValidatedData::string($intent['role'] ?? null));
        if ($intent['created'] !== false || $assignment->role !== $role || ! in_array($assignment->node_id, [$sourceId, $targetId], true)
            || $node->id !== ($operation->kind === 'node.role_relocate_source' ? $sourceId : $targetId)
            || ($operation->kind === 'node.role_relocate' ? ($input['role'] ?? null) !== $role->value : ($input['assignment_id'] ?? null) !== $assignment->id)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The transfer differs from its retained role assignment.', 422);
        }
        $steps = [];
        if ($operation->kind === 'node.role_relocate') {
            $steps[] = ['type' => 'child', 'key' => 'role-relocation-source', 'kind' => 'node.role_relocate_source', 'node_id' => $sourceId,
                'input' => ['assignment_id' => $assignment->id], 'optional' => false];
            $metrics = $role === RoleName::Metrics ? $node : Node::query()->whereHas('roles',
                static fn ($query) => $query->where('role', RoleName::Metrics)->where('status', LifecycleStatus::Active))->first();
            if ($metrics !== null) {
                throw new ResourceOperationException('metrics.native_composition_required', 'This parent requires Metrics stages in the same native run.', 409);
            }
        } else {
            $ghost = clone $assignment;
            $ghost->node_id = $node->id;
            $ghost->setRelation('node', $node);
            if ($operation->kind === 'node.role_relocate_source') {
                $steps[] = ['type' => 'child', 'key' => 'role-relocation-target', 'kind' => 'node.role_relocate_target', 'node_id' => $targetId,
                    'input' => ['assignment_id' => $assignment->id], 'optional' => false];
                $steps = [...$steps, ...$this->plan->remove($node, $ghost, false, false)];
            } else {
                $this->operatingSystem->assert($node, $role);
                $account = $this->accounts->resolve($node);
                if ($role === RoleName::Gateway) {
                    $steps[] = ['type' => 'run', 'key' => 'role-target-firewall', 'transport' => 'agent', 'name' => 'firewall.rules',
                        'input' => app(NodeRoleFirewallInput::class)->converge($node, $role), 'result' => ['matches' => true], 'optional' => false];
                }
                $steps[] = ['type' => 'run', 'key' => 'role-transfer', 'transport' => 'gateway', 'name' => 'node.transfer-role',
                    'input' => [], 'result' => ['transferred' => true], 'optional' => false];
                $steps = [...$steps, ...$this->plan->converge($node, $ghost, $account)];
                $steps[] = ['type' => 'child', 'key' => 'role-footprint', 'kind' => 'node.converge', 'node_id' => $node->id,
                    'input' => ['force_artifacts' => false, 'first_visit' => false], 'optional' => true];
                $steps[] = ['type' => 'child', 'key' => 'role-cli', 'kind' => 'fleet.cli_converge', 'node_id' => $node->id, 'input' => [], 'optional' => true];
            }
        }

        return $this->persist($operation, $steps);
    }

    /** @param list<array<string,mixed>> $steps
     * @return array<string,mixed>
     */
    private function persist(Operation $operation, array $steps): array
    {
        $intents = [];
        foreach ($steps as $step) {
            if ($step['type'] === 'child') {
                $intents[] = ['key' => $step['key'], 'kind' => $step['kind'], 'node_id' => $step['node_id'], 'input' => $step['input']];
            }
        }
        if (($operation->state['role_first_managed'] ?? false) === true) {
            $intents[] = ['key' => 'role-network-rollback', 'kind' => 'node.network_rollback', 'node_id' => $operation->node_id, 'input' => []];
        }
        $this->store->change($operation, ['input' => [...$operation->input, '_role_plan' => $steps],
            'state' => [...($operation->state ?? []), 'node_role_prepared' => true, 'child_intents' => $intents]]);

        return ['prepared' => true];
    }
}
