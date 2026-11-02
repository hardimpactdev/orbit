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
use App\Support\ValidatedData;

/** Renders the hub's wg-quick file and its peer sections as public intent. The hub's private key never leaves the Node. */
final readonly class AdmitNodeWireGuardHubOperationAction
{
    public const string KeyFile = '/etc/wireguard/orbit.key';

    public const string PeerDirectory = '/etc/wireguard/orbit-peers.d';

    /** A run puts the public key it prepared where this mark stands in a peer section. */
    public const string KeyMark = '@KEY@';

    public function __construct(private StartOperationAction $operations) {}

    public function execute(Node $node, ?string $requestId = null): Operation
    {
        // Swamp reaches the hub over SSH from 209, so no Node worker placement applies.
        if (! app(ManagedNodeEligibility::class)->allows($node)
            || ! $node->roles()->where('role', RoleName::Vpn)->where('status', LifecycleStatus::Active)->exists()) {
            throw new ResourceOperationException('node.network_unsupported', 'Hub convergence requires its exact active managed VPN Node.', 422);
        }
        $intent = self::intent($node);

        return $this->operations->execute('node.wireguard_hub_converge', 'node', $node->id, $node->id,
            ['intent' => $intent, 'participant_node_ids' => [$node->id]], 'operator', $requestId);
    }

    /**
     * The hub's wg-quick file and one peer file per Node, from Gateway records. orbit.conf holds no peer: wg-quick loads
     * every file in the peer directory on start. A run that adds one peer writes only that peer's file, so concurrent
     * runs never overwrite each other's peers. Stale files are pruned; a Node still provisioning keeps its file.
     *
     * @return array<string,mixed>
     */
    public static function intent(Node $hub): array
    {
        $peers = Node::query()->whereKeyNot($hub->id)->whereNotNull('wireguard_public_key')->whereNotNull('wireguard_ip')
            ->where('status', '!=', LifecycleStatus::Removing)->orderBy('id')->get();
        $enrolling = ValidatedData::integerList(Node::query()->whereKeyNot($hub->id)->where('status', LifecycleStatus::Provisioning)->pluck('id')->all());
        $settings = app(VpnSettings::class);
        $port = filter_var($settings->port(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65_535]]);
        if (! is_int($port) || ! is_string($hub->wireguard_ip)) {
            throw new ResourceOperationException('node.network_unsupported', 'The hub needs its WireGuard address and a valid port.', 422);
        }
        $keep = array_values(array_unique([...ValidatedData::integerList($peers->pluck('id')->all()), ...$enrolling]));
        sort($keep);

        // The hub's private key never leaves the Node: orbit.conf reads its key file, and the apply step composes the key
        // with the peer files on the Node at apply time. No file, the intent nor the run holds it.
        return ['hub_id' => $hub->id, 'ssh' => app(SwampSshExecutionInput::class)->forNode($hub), 'port' => $port,
            'config' => "[Interface]\nAddress = {$hub->wireguard_ip}/".Ipv4Subnet::from($settings->subnet())->prefixLength()
                ."\nListenPort = {$port}\nPostUp = wg set %i private-key ".self::KeyFile
                ."\nPostUp = for f in ".self::PeerDirectory.'/*.conf; do [ -e "$f" ] && wg addconf %i "$f"; done; true'."\n",
            'peers' => $peers->map(static fn (Node $peer): array => ['node_id' => $peer->id, 'file' => self::peerFile($peer->id),
                'content' => self::section($peer->name, (string) $peer->wireguard_public_key, "{$peer->wireguard_ip}/32")])->values()->all(),
            'prune_argv' => ['find', self::PeerDirectory, '-maxdepth', '1', '-name', '*.conf',
                ...array_merge(...array_map(static fn (int $id): array => ['!', '-name', "{$id}.conf"], $keep)), '-delete'],
            'peer_keys' => ValidatedData::stringList($peers->pluck('wireguard_public_key')->values()->all())];
    }

    /**
     * What a run that adds one Node to the hub needs: the hub's SSH target and that Node's peer file, with a mark where
     * the run puts the public key it prepared.
     *
     * @return array<string,mixed>
     */
    public static function joining(Node $hub, int $nodeId, string $name, string $address): array
    {
        return ['hub_id' => $hub->id, 'ssh' => app(SwampSshExecutionInput::class)->forNode($hub), 'file' => self::peerFile($nodeId),
            'section' => self::section($name, self::KeyMark, "{$address}/32")];
    }

    public static function peerFile(int $nodeId): string
    {
        return self::PeerDirectory."/{$nodeId}.conf";
    }

    /** One hub peer section. */
    public static function section(string $name, string $publicKey, string $allowed): string
    {
        return "[Peer]\n# {$name}\nPublicKey = {$publicKey}\nAllowedIPs = {$allowed}\n";
    }
}
