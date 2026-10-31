<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Processes\ProcessSwampSafety;
use App\Domain\Routes\PrivateDnsPublicationNodes;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\TaskVm;

/** Records each serving Node separately. Route source relations outlive every cleanup child. */
final readonly class NodeRemovalPlan
{
    public function __construct(private NodeRoleFirewallInput $firewall) {}

    /** @return list<array<string,mixed>> */
    public function build(Node $node, bool $offline): array
    {
        $steps = [];
        if ($offline) {
            foreach ($node->roles()->orderBy('role')->get() as $role) {
                $steps[] = $this->child('remove-role-'.$role->id, 'node.role_remove', $node->id,
                    ['role' => $role->role->value, 'purge_data' => false, 'offline' => true]);
            }
        }
        $steps[] = $this->child('remove-begin', 'node.removal_begin', $node->id, []);
        $metrics = NodeRole::query()->where('role', RoleName::Metrics)->where('node_id', '!=', $node->id)->where('status', LifecycleStatus::Active)->first();
        if ($metrics !== null && ! TaskVm::query()->where('node_id', $node->id)->exists()) {
            throw new ResourceOperationException('metrics.native_composition_required', 'This parent requires Metrics stages in the same native run.', 409);
        }
        if (! $offline && $node->platform === 'linux') {
            $steps[] = ['type' => 'run', 'key' => 'remove-agent', 'transport' => 'agent', 'name' => 'agent.remove',
                'input' => [], 'result' => [], 'optional' => true];
            if ($node->wireguard_public_key !== null) {
                $steps[] = ['type' => 'run', 'key' => 'remove-public-ssh', 'transport' => 'agent', 'name' => 'firewall.rules',
                    'input' => $this->firewall->publicSsh($node), 'result' => ['matches' => true], 'optional' => false];
            }
        }
        if ($node->wireguard_public_key !== null) {
            $hub = NodeRole::query()->where('role', RoleName::Vpn)->sole();
            $steps[] = $this->child('remove-hub', 'node.wireguard_hub_converge', $hub->node_id,
                ['context_node_id' => $node->id, 'excluded_node_id' => $node->id]);
        }
        $serving = Node::query()->whereKeyNot($node->id)->where('status', LifecycleStatus::Active)->orderBy('id')->get();
        foreach ($serving as $target) {
            if ($target->platform !== 'linux' || $target->ssh_host_fingerprint === null || $target->wireguard_ip === null) {
                continue;
            }
            if (! app(ProcessSwampSafety::class)->requiresSynchronous($target)) {
                $steps[] = $this->child('remove-network-'.$target->id, 'node.network_projection', $target->id,
                    ['context_node_id' => $node->id, 'phase' => 'prune']);
            }
            $steps[] = $this->child('remove-residue-'.$target->id, 'route.offline_residue_clean', $target->id,
                ['removed_node_id' => $node->id]);
            if (PrivateDnsPublicationNodes::query()->whereKey($target->id)->exists()) {
                $steps[] = $this->child('remove-dns-'.$target->id, 'route.private_dns_publish', $target->id,
                    ['reason' => 'node removal']);
            }
        }

        return $steps;
    }

    /** @return list<array<string,mixed>> */
    public function restore(Node $node): array
    {
        $steps = [['type' => 'run', 'key' => 'restore-state', 'transport' => 'gateway', 'name' => 'node.restore-removal-state',
            'input' => [], 'result' => ['restoring' => true], 'optional' => false]];
        if ($node->wireguard_public_key !== null) {
            $hub = NodeRole::query()->where('role', RoleName::Vpn)->sole();
            $steps[] = $this->child('restore-hub', 'node.wireguard_hub_converge', $hub->node_id,
                ['context_node_id' => $node->id, 'excluded_node_id' => null]);
        }
        foreach (Node::query()->whereKeyNot($node->id)->where('status', LifecycleStatus::Active)->orderBy('id')->get() as $target) {
            if ($target->platform !== 'linux' || $target->ssh_host_fingerprint === null || $target->wireguard_ip === null) {
                continue;
            }
            if (! app(ProcessSwampSafety::class)->requiresSynchronous($target)) {
                $steps[] = $this->child('restore-network-'.$target->id, 'node.network_projection', $target->id,
                    ['context_node_id' => $node->id, 'phase' => 'expand']);
            }
            if (PrivateDnsPublicationNodes::query()->whereKey($target->id)->exists()) {
                $steps[] = $this->child('restore-dns-'.$target->id, 'route.private_dns_publish', $target->id, ['reason' => 'node removal restored']);
            }
        }
        $metrics = NodeRole::query()->where('role', RoleName::Metrics)->where('node_id', '!=', $node->id)->where('status', LifecycleStatus::Active)->first();
        if ($metrics !== null && ! TaskVm::query()->where('node_id', $node->id)->exists()) {
            throw new ResourceOperationException('metrics.native_composition_required', 'This parent requires Metrics stages in the same native run.', 409);
        }

        return $steps;
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function child(string $key, string $kind, int $nodeId, array $input): array
    {
        return ['type' => 'child', 'key' => $key, 'kind' => $kind, 'node_id' => $nodeId, 'input' => $input, 'optional' => false];
    }
}
