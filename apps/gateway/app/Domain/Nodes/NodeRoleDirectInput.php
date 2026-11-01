<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Fleet\CliConvergePlan;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolManagerIntent;
use App\Domain\Tools\ToolManagerName;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Gateway\GatewayPrivateDnsResolver;
use App\Infrastructure\MachineSoftware\NodeRecipeProgram;
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
        private CliConvergePlan $cli, private NodeRoleRuntimeInput $runtime, private ToolManagerIntent $managers) {}

    public static function supports(RoleName $role): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function capture(Node $node, NodeRole $assignment, bool $created): array
    {
        if ($assignment->node_id !== $node->id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The role snapshot requires its admitted Node assignment.', 422);
        }
        $role = $assignment->role;
        $prerequisites = $this->prerequisites->input($node, $role,
            new ManagedUserAccount($node->user, NodeConvergeInput::GroupToken, NodeConvergeInput::HomeToken));
        unset($prerequisites['user'], $prerequisites['group'], $prerequisites['home']);
        $footprint = $this->footprint->capture($node, false, false);
        unset($footprint['secret_hash']);

        return ['proxy_node_ids' => app(NodeRolePlacement::class)->proxyNodeIds(), 'schema_version' => 1, 'node_id' => $node->id, 'agent_sha256' => $this->delivery->pin($node),
            'assignment_id' => $assignment->id, 'role' => $role->value, 'created' => $created, 'user' => $node->user,
            'prerequisites' => $prerequisites, 'firewall' => $role === RoleName::Database ? null : $this->firewall->converge($node, $role),
            'footprint' => $footprint,
            'javascript' => in_array($role, [RoleName::AppDev, RoleName::AppProd], true) ? NodeRecipeProgram::javascript($node, $role) : null,
            'storage_root' => $role === RoleName::AppDev ? (ValidatedData::object($node->settings['apps'] ?? [])['path'] ?? null) : null,
            'router_lan' => $role === RoleName::Router ? $this->firewall->routerLan($node) : null,
            'managers' => in_array($role, [RoleName::AppDev, RoleName::AppProd], true)
                ? array_map(fn (ToolManagerName $manager): array => ['manager' => $manager->value, 'platform' => $node->platform,
                    'architecture' => MachineArchitecture::normalize($node->architecture), 'user' => $node->user,
                    'materialize' => $this->managers->input($node, $manager)['materialize'] ?? null], [ToolManagerName::Vp, ToolManagerName::Composer]) : [],
            'publication' => app(NodeRolePublicationInput::class)->capture($node, $role),
            'certificates' => app(NodeRoleCertificateInput::class)->capture($node, $role),
            'metrics_fleet' => app(NodeRoleMetricsInput::class)->capture($assignment),
            'gateway_dns' => $role === RoleName::Gateway && ($dns = app(GatewayPrivateDnsResolver::class)->vpnDnsAddress(app(VpnSettings::class))) !== null
                ? ['operation' => 'converge', 'address' => $dns, 'domain' => app(VpnSettings::class)->domain()] : null,
            'cli' => $this->cli->input($node), 'runtime' => $this->runtime->capture($node, $assignment)];
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
        if (isset($operation->input['native_role']) || ! in_array($operation->kind, ['node.role_add', 'node.role_converge'], true) || $operation->target_type !== 'node'
            || $operation->node_id === null || $operation->target_id !== $operation->node_id
            || $operation->status->terminal()
            || ($snapshot['node_id'] ?? null) !== $operation->node_id
            || ! is_string($snapshot['role'] ?? null) || RoleName::tryFrom($snapshot['role']) === null
            || ! NodeRole::query()->whereKey($snapshot['assignment_id'] ?? null)
                ->where('node_id', $operation->node_id)->where('role', $snapshot['role'])->exists()) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The role snapshot does not belong to this standalone Node root.', 422);
        }

        return $snapshot;
    }
}
