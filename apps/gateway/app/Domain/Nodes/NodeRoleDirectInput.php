<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Fleet\CliConvergePlan;
use App\Domain\Metrics\MetricsRoleReconcileImpact;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
use App\Infrastructure\Nodes\Roles\RolePrerequisites;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Support\ValidatedData;
use InvalidArgumentException;

/** Public intent only. Account discovery and every effect belong to the native root. */
final readonly class NodeRoleDirectInput
{
    public function __construct(private AgentDelivery $delivery, private RolePrerequisites $prerequisites,
        private NodeRoleFirewallInput $firewall, private NodeConvergeInput $footprint,
        private MetricsRoleReconcileImpact $metricsImpact, private CliConvergePlan $cli) {}

    public static function supports(RoleName $role): bool
    {
        return in_array($role, [RoleName::Database, RoleName::CiRunner], true);
    }

    /** @param array{metrics_node_id:int,selected:bool,caddy:bool,fpm:bool}|null $metricsBefore Complete owning capture before recording the role transition.
     * @return array<string,mixed>
     */
    public function capture(Node $node, NodeRole $assignment, bool $created, ?array $metricsBefore = null): array
    {
        if ($assignment->node_id !== $node->id || ! self::supports($assignment->role)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The role snapshot requires its admitted Node assignment.', 422);
        }
        $after = $this->metricsImpact->capture($node, $assignment->role);
        $metricsNodeId = $after['metrics_node_id'] ?? null;
        $impact = $after === null && $metricsBefore === null ? null : $this->metricsImpact->compare($metricsBefore, $after);
        $role = $assignment->role;
        $prerequisites = $this->prerequisites->input($node, $role,
            new ManagedUserAccount($node->user, NodeConvergeInput::GroupToken, NodeConvergeInput::HomeToken));
        unset($prerequisites['user'], $prerequisites['group'], $prerequisites['home']);
        $footprint = $this->footprint->capture($node, false, false);
        unset($footprint['secret_hash']);

        return ['schema_version' => 1, 'node_id' => $node->id, 'agent_sha256' => $this->delivery->pin($node),
            'assignment_id' => $assignment->id, 'role' => $role->value, 'created' => $created, 'user' => $node->user,
            'prerequisites' => $prerequisites, 'firewall' => $role === RoleName::Database ? null : $this->firewall->converge($node, $role),
            'footprint' => $footprint,
            'metrics_node_id' => $metricsNodeId, 'metrics_impact' => $impact, 'cli' => $this->cli->input($node)];
    }

    /** Canonical read-only ownership check for the role root and its protected hash source. */
    public static function ownerId(Operation $operation): ?int
    {
        try {
            return ValidatedData::integer(self::bound($operation)['node_id']);
        } catch (ResourceOperationException|InvalidArgumentException) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    public static function bound(Operation $operation): array
    {
        $snapshot = ValidatedData::object($operation->input['_role'] ?? []);
        $intent = ValidatedData::object($operation->state['role_intent'] ?? []);
        $footprint = ValidatedData::object($snapshot['footprint'] ?? []);
        $run = $operation->state['swamp_run_id'] ?? null;
        $role = $snapshot['role'] ?? null;
        $pin = $snapshot['agent_sha256'] ?? null;
        if ($operation->kind !== 'node.role_add'
            || $operation->target_type !== 'node' || $operation->node_id === null || $operation->target_id !== $operation->node_id
            || $operation->parent_operation_id !== null || $operation->status->terminal()
            || ($operation->state['node_role_writer'] ?? null) !== 'direct'
            || ($operation->state['role_owned_by_parent'] ?? false) !== false
            || ! is_string($run) || preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $run) !== 1
            || ($snapshot['schema_version'] ?? null) !== 1 || ($snapshot['node_id'] ?? null) !== $operation->node_id
            || ($operation->input['node_id'] ?? null) !== $operation->node_id
            || ! in_array($role, ['database', 'ci-runner'], true) || ($operation->input['role'] ?? null) !== $role
            || ! is_string($pin) || preg_match('/\A[a-f0-9]{64}\z/D', $pin) !== 1
            || ($footprint['schema_version'] ?? null) !== 1 || ($footprint['node_id'] ?? null) !== $operation->node_id
            || ($footprint['agent_sha256'] ?? null) !== $pin || array_key_exists('secret_hash', $footprint)
            || ! is_int($snapshot['assignment_id'] ?? null) || ! is_bool($snapshot['created'] ?? null)
            || ($intent['assignment_id'] ?? null) !== $snapshot['assignment_id'] || ($intent['created'] ?? null) !== $snapshot['created']
            || ($intent['role'] ?? null) !== $role || ($intent['source_node_id'] ?? null) !== $operation->node_id
            || ($intent['target_node_id'] ?? null) !== null
            || ! NodeRole::query()->whereKey($snapshot['assignment_id'])->where('node_id', $operation->node_id)->where('role', $role)->exists()) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The role snapshot does not belong to this standalone Node root.', 422);
        }

        return $snapshot;
    }
}
