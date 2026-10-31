<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Domain\Metrics\ExporterSelector;
use App\Domain\Routes\CaddyPublicationPlan;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
use App\Models\Node;
use App\Models\NodeRole;

/** Public removal intent. The caller holds the Node row lock and has marked the assignment removing. */
final readonly class NodeRoleRemoveInput
{
    public function __construct(private AgentDelivery $delivery, private NodeRoleFirewallInput $firewall,
        private RoleRegistry $roles, private NodeRoleRuntimeInput $runtime) {}

    /** @return array<string,mixed> */
    public function capture(Node $node, NodeRole $assignment, bool $purgeData = false, bool $offline = false, bool $deleted = false): array
    {
        $role = $assignment->role;
        if ($assignment->node_id !== $node->id || $assignment->status !== LifecycleStatus::Removing
            || ! $this->roles->definition($role)->mutable) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Removal requires its claimed Node role assignment.', 422);
        }
        if (($role === RoleName::AppDev && $node->instances()->exists()) || $deleted || in_array($role, [RoleName::Gateway, RoleName::AppDev, RoleName::Metrics, RoleName::WebSocket, RoleName::Analytics], true)) {
            throw new ResourceOperationException('operation.kind_unavailable', 'The applicable removal publication or runtime requires its owning native composition.', 422);
        }
        $metrics = NodeRole::query()->where('role', RoleName::Metrics)
            ->whereIn('status', [LifecycleStatus::Active, LifecycleStatus::Provisioning])->exists();
        $remaining = $node->roles()->whereKeyNot($assignment->id)
            ->whereIn('status', [LifecycleStatus::Active, LifecycleStatus::Provisioning])->get()
            ->map(static fn (NodeRole $remaining): RoleName => $remaining->role)->values()->all();
        if ($metrics) {
            $selector = app(ExporterSelector::class);
            $preference = app(ExporterPreferenceRepository::class)->get($node->id);
            $before = $selector->select([...$remaining, $role], true, $preference);
            $after = $selector->select(array_values($remaining), true, $preference);
            if ($before->selected !== $after->selected || in_array($role, [RoleName::AppProd, RoleName::Ingress], true)) {
                throw new ResourceOperationException('operation.kind_unavailable', 'This removal changes the Metrics fleet and needs its native composition.', 422);
            }
        }

        return ['schema_version' => 1, 'node_id' => $node->id, 'assignment_id' => $assignment->id,
            'role' => $role->value, 'agent_sha256' => $this->delivery->pin($node), 'user' => $node->user,
            'purge_data' => $purgeData, 'offline' => $offline,
            'publication' => app(NodeRolePublicationInput::class)->capture($node, $role),
            'runtime' => $this->runtime->removal($node, $role, $purgeData),
            'caddy' => in_array($role, [RoleName::AppProd, RoleName::Ingress], true) ? CaddyPublicationPlan::forNode($node) : null,
            'firewall' => $role === RoleName::Database ? null : $this->firewall->remove($node, $role),
            'public_ssh' => $node->roles()->whereKeyNot($assignment->id)->doesntExist() ? $this->firewall->publicSsh($node) : null];
    }
}
