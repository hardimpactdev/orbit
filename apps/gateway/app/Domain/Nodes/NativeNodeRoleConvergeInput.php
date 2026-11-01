<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Routes\NativeCaddyPublicationInput;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\AgentCaddyPackage;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\NodeRole;
use App\Support\ValidatedData;

/** Public admission capture for the role sets executed entirely from 209. */
final readonly class NativeNodeRoleConvergeInput
{
    public function __construct(private SwampSshExecutionInput $ssh) {}

    public static function supports(Node $node, RoleName $role): bool
    {
        return in_array($role, [RoleName::Database, RoleName::Ingress], true) && $node->roles()->where('role', $role)->exists() && ! $node->roles()->where('role', '!=', $role)->exists();
    }

    /** @param array<string,mixed> $snapshot
     * @return array{intent:array<string,mixed>,participant_node_ids:list<int>}
     */
    public function capture(Node $node, NodeRole $assignment, array $snapshot): array
    {
        if ($snapshot['metrics_fleet'] !== null || ! self::supports($node, $assignment->role) || $assignment->node_id !== $node->id || ! $assignment->exists) {
            throw new ResourceOperationException('operation.kind_unavailable', 'This native role factory requires an actual supported sole-role assignment.', 422);
        }
        $footprint = ValidatedData::object($snapshot['footprint']);
        $entries = $footprint['artifacts'];
        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new ResourceOperationException('operation.kind_unavailable', 'The footprint requires its complete artifact list.', 422);
        }
        foreach ($entries as $artifact) {
            $artifact = ValidatedData::object($artifact);
            if ($artifact['required'] === true && ! in_array($artifact['name'], $assignment->role === RoleName::Ingress ? ['agent', 'peer-dns', 'caddy-package', 'caddy'] : ['agent', 'peer-dns'], true)) {
                throw new ResourceOperationException('operation.kind_unavailable', 'An applicable role footprint has no native action.', 422);
            }
        }
        $ssh = $this->ssh->forNode($node);
        $intent = ['schema_version' => 1, 'node_id' => $node->id, 'assignment_id' => $assignment->id,
            'created' => false, 'role' => $assignment->role->value, 'user' => $node->user,
            'agent_sha256' => $snapshot['agent_sha256'], 'prerequisites' => $snapshot['prerequisites'],
            'ssh' => $ssh,
            ...($assignment->role === RoleName::Ingress ? ['caddy' => app(NativeCaddyPublicationInput::class)->forNode($node),
                'caddy_package' => AgentCaddyPackage::input(), 'firewall' => app(NodeRoleFirewallInput::class)->convergeCommands($node, $assignment->role)] : []),
            'footprint' => $footprint, 'cli' => $snapshot['cli'], 'metrics_fleet' => null];

        return ['intent' => $intent, 'participant_node_ids' => array_values(array_unique([209, $node->id]))];
    }
}
