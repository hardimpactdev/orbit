<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Domain\WireGuard\VpnSettings;
use App\Models\Node;

/** Pure parent capture. Existing hub key custody stays on the hub; no private source read. */
final readonly class NodeWireGuardCommandInput
{
    /** Capture before changing the context Node's admitted identity or status.
     * @return array<string,mixed>
     */
    public function capture(Node $context, ?string $prospectiveAddress = null): array
    {
        $bound = app(NodeWireGuardInput::class)->capture($context);
        $hub = Node::query()->findOrFail($bound['hub_id']);
        $settings = app(VpnSettings::class);
        $subnet = Ipv4Subnet::from($settings->subnet());
        $address = $prospectiveAddress ?? $context->wireguard_ip;
        if ($context->id === $hub->id || $address === $hub->wireguard_ip || ! is_string($address) || ! $subnet->containsUsableAddress($address)
            || ! is_string($hub->wireguard_ip) || ! $subnet->containsUsableAddress($hub->wireguard_ip)) {
            throw new ResourceOperationException('node.network_unsupported', 'Hub peer composition requires one admitted peer address.', 422);
        }
        $inventory = Node::query()->whereKeyNot($hub->id)->whereNotNull('wireguard_ip')->whereNotNull('wireguard_public_key')
            ->where(fn ($query) => $query->where('status', '!=', LifecycleStatus::Removing)->orWhereKey($context->id))
            ->orderBy('id')->get()->map(self::peer(...))->all();
        $before = array_find($inventory, static fn (array $peer): bool => $peer['node_id'] === $context->id);

        return ['schema_version' => 1, 'hub_id' => $hub->id, 'hub_address' => $hub->wireguard_ip.'/'.$subnet->prefixLength(),
            'port' => (int) $settings->port(), 'context_node_id' => $context->id, 'inventory' => $inventory, 'before' => $before,
            'desired' => ['node_id' => $context->id, 'name' => $context->name, 'address' => $address, 'public_key' => $context->wireguard_public_key]];
    }

    /** @return array{node_id:int,name:string,address:string,public_key:string} */
    private static function peer(Node $node): array
    {
        if (! is_string($node->wireguard_ip) || ! is_string($node->wireguard_public_key)) {
            throw new ResourceOperationException('node.network_unsupported', 'The captured hub peer has no public network identity.', 422);
        }

        return ['node_id' => $node->id, 'name' => $node->name, 'address' => $node->wireguard_ip, 'public_key' => $node->wireguard_public_key];
    }
}
