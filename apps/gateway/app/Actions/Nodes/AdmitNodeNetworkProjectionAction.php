<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Firewall\RouterLanIngressPolicy;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Node;
use App\Models\Operation;

/** Captures public network policy for one serving Node. No VPN credential is read. */
final readonly class AdmitNodeNetworkProjectionAction
{
    public function __construct(private StartOperationAction $operations, private OperationIdempotency $idempotency,
        private OperationStore $store, private NodeFirewallRuleCatalog $firewall, private PeerDnsPolicy $dns) {}

    /** @param array{key:string,kind:string,node_id:int|null,input:array<string,mixed>} $intent */
    public function execute(Operation $parent, array $intent): Operation
    {
        if (($existing = $this->idempotency->replay('node.network_projection')) !== null) {
            return $existing;
        }
        $input = $intent['input'];
        $phase = $input['phase'] ?? null;
        $expected = match ($parent->kind) {
            'node.remove' => 'prune',
            'node.add', 'node.removal_restore', 'node.enrollment_restore' => 'expand',
            default => null,
        };
        if ($this->idempotency->parent?->id !== $parent->id || $this->idempotency->childKey !== $intent['key']
            || $expected === null || $phase !== $expected || ($input['context_node_id'] ?? null) !== $parent->node_id
            || count($input) !== 2 || ! is_int($intent['node_id'])) {
            throw new ResourceOperationException('operation.child_forbidden', 'The network projection differs from its owning lifecycle intent.', 422);
        }
        $context = Node::query()->findOrFail($parent->node_id);
        $node = Node::query()->with('roles')->findOrFail($intent['node_id']);
        if ($node->is($context) || $node->platform !== 'linux' || $node->status !== LifecycleStatus::Active
            || $node->wireguard_ip === null || $node->ssh_host_fingerprint === null) {
            throw new ResourceOperationException('operation.child_forbidden', 'The network projection requires another managed serving Node.', 422);
        }
        $steps = [];
        if ($node->roles->contains(fn ($role): bool => $role->role === RoleName::Router && $role->status === LifecycleStatus::Active)
            && $context->cluster_id !== null && $context->cluster_id === $node->cluster_id && (new RouterLanIngressPolicy)->routerHasContactableLan($node)) {
            $overrides = [$context->id => ['status' => $phase === 'prune' ? LifecycleStatus::Removing : LifecycleStatus::Active]];
            $desired = array_map(static fn ($rule): array => $rule->shape->toAgentInput(), $this->firewall->routerLanIngress($node, $overrides));
            $steps[] = $this->run('network-router-lan', 'firewall.router-lan',
                ['desired' => $desired, 'expand' => $phase === 'expand', 'prune' => $phase === 'prune'], ['matches' => true]);
        }
        if ($this->dns->manages($node)) {
            $steps[] = $this->run('network-peer-dns', 'wireguard.repair-peer-dns',
                ['dns_server' => $this->dns->server($node), 'public_key' => $node->wireguard_public_key,
                    'domains' => $this->dns->domains($context, $phase === 'prune')], []);
        }
        $operation = $this->operations->execute('node.network_projection', 'node', $node->id, $node->id, $input, 'node-network', $parent->request_id);
        if ($operation->wasRecentlyCreated) {
            $this->store->change($operation, ['input' => [...$operation->input, '_node_plan' => $steps,
                '_node_result' => ['projected' => true], '_node_failure_result' => ['projected' => false]],
                'state' => [...($operation->state ?? []), 'child_intents' => []]]);
        }

        return $operation;
    }

    /** @param array<string,mixed> $input
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function run(string $key, string $name, array $input, array $result): array
    {
        return ['type' => 'run', 'key' => $key, 'transport' => 'agent', 'name' => $name, 'input' => $input, 'result' => $result, 'optional' => false];
    }
}
