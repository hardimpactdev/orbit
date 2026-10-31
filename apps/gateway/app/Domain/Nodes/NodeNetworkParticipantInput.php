<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Firewall\RouterLanIngressPolicy;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Node;

/** Public programs for one admitted participant in the owning parent DAG. */
final readonly class NodeNetworkParticipantInput
{
    public function __construct(private NodeFirewallRuleCatalog $firewall, private PeerDnsPolicy $dns, private AgentDelivery $agent) {}

    /** @return array<string,mixed> */
    public function capture(Node $node, Node $context, bool $prune): array
    {
        $node->load('roles');
        $router = null;
        if ($node->roles->contains(fn ($role): bool => $role->role === RoleName::Router && $role->status === LifecycleStatus::Active)
            && $context->cluster_id !== null && $context->cluster_id === $node->cluster_id
            && (new RouterLanIngressPolicy)->routerHasContactableLan($node)) {
            $overrides = [$context->id => ['status' => $prune ? LifecycleStatus::Removing : LifecycleStatus::Active]];
            $router = ['desired' => array_map(static fn ($rule): array => $rule->shape->toAgentInput(),
                $this->firewall->routerLanIngress($node, $overrides)), 'expand' => ! $prune, 'prune' => $prune];
        }

        return ['schema_version' => 1, 'node_id' => $node->id, 'agent_sha256' => $this->agent->pin($node),
            'router_lan' => $router, 'peer_dns' => $this->dns->manages($node)
                ? ['dns_server' => $this->dns->server($node), 'public_key' => $node->wireguard_public_key,
                    'domains' => $this->dns->domains($context, $prune)] : null];
    }
}
