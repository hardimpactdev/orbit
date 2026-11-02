<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Operation;

/** Renders the hub's wg-quick file and its peer sections as public intent. The hub's private key never leaves the Node. */
final readonly class AdmitNodeWireGuardHubOperationAction
{
    public const string KeyFile = '/etc/wireguard/orbit.key';

    public function __construct(private StartOperationAction $operations) {}

    public function execute(Node $node, ?string $requestId = null): Operation
    {
        // Swamp reaches the hub over SSH from 209, so no Node worker placement applies.
        if (! app(ManagedNodeEligibility::class)->allows($node)
            || ! $node->roles()->where('role', RoleName::Vpn)->where('status', LifecycleStatus::Active)->exists()) {
            throw new ResourceOperationException('node.network_unsupported', 'Hub convergence requires its exact active managed VPN Node.', 422);
        }
        $peers = Node::query()->whereKeyNot($node->id)->whereNotNull('wireguard_public_key')->whereNotNull('wireguard_ip')
            ->where('status', '!=', LifecycleStatus::Removing)->orderBy('id')->get();
        $settings = app(VpnSettings::class);
        $port = filter_var($settings->port(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65_535]]);
        if (! is_int($port) || ! is_string($node->wireguard_ip)) {
            throw new ResourceOperationException('node.network_unsupported', 'The hub needs its WireGuard address and a valid port.', 422);
        }
        $sections = $peers->map(static fn (Node $peer): string => "\n[Peer]\n# {$peer->name}\nPublicKey = {$peer->wireguard_public_key}\nAllowedIPs = {$peer->wireguard_ip}/32\n")->implode('');
        // The hub's private key never leaves the Node: orbit.conf reads its key file, and the apply step composes the key
        // with the peers file on the Node at apply time. Neither file, the intent nor the run holds it.
        $intent = ['hub_id' => $node->id, 'ssh' => app(SwampSshExecutionInput::class)->forNode($node),
            'config' => "[Interface]\nAddress = {$node->wireguard_ip}/".Ipv4Subnet::from($settings->subnet())->prefixLength()
                ."\nListenPort = {$port}\nPostUp = wg set %i private-key ".self::KeyFile."\n".$sections,
            'peers' => $sections, 'port' => $port,
            'peer_keys' => $peers->pluck('wireguard_public_key')->values()->all()];

        return $this->operations->execute('node.wireguard_hub_converge', 'node', $node->id, $node->id,
            ['intent' => $intent, 'participant_node_ids' => [$node->id]], 'operator', $requestId);
    }
}
