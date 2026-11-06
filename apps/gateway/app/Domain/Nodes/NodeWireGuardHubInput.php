<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Support\ValidatedData;

/** Renders the hub's wg-quick file and its peer sections as public intent. The hub's private key never leaves the Node. */
final readonly class NodeWireGuardHubInput
{
    public const string KeyFile = '/etc/wireguard/orbit.key';

    public const string PeerDirectory = '/etc/wireguard/orbit-peers.d';

    /** A run puts the public key it prepared where this mark stands in a peer section. */
    public const string KeyMark = '@KEY@';

    /**
     * The hub's wg-quick file and one peer file per Node, from Gateway records. orbit.conf holds no peer: wg-quick loads
     * every file in the peer directory on start. A run that adds one peer writes only that peer's file, so concurrent
     * runs never overwrite each other's peers. Only the files of Nodes that are gone or being removed are pruned: every
     * other Node keeps its file, whatever its status and whether or not the Gateway has recorded its key, because its own run may have written it.
     *
     * @return array{hub_id:int,ssh:array<string,mixed>,port:int,config:string,peers:list<array{node_id:int,file:string,content:string}>,prune_argv:list<string>,peer_keys:list<string>}
     */
    public static function intent(Node $hub): array
    {
        $peers = Node::query()->whereKeyNot($hub->id)->whereNotNull('wireguard_public_key')->whereNotNull('wireguard_ip')
            ->where('status', '!=', LifecycleStatus::Removing)->orderBy('id')->get();
        $keep = ValidatedData::integerList(Node::query()->whereKeyNot($hub->id)->where('status', '!=', LifecycleStatus::Removing)->orderBy('id')->pluck('id')->all());
        $settings = app(VpnSettings::class);
        $port = filter_var($settings->port(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65_535]]);
        if (! is_int($port) || ! is_string($hub->wireguard_ip)) {
            throw new ResourceOperationException('node.network_unsupported', 'The hub needs its WireGuard address and a valid port.', 422);
        }

        // The hub's private key never leaves the Node: orbit.conf reads its key file, and the apply step composes the key
        // with the peer files on the Node at apply time. No file, the intent nor the run holds it.
        return ['hub_id' => $hub->id, 'ssh' => app(SwampSshExecutionInput::class)->forNode($hub), 'port' => $port,
            'config' => self::config($hub),
            'peers' => array_values($peers->map(static fn (Node $peer): array => ['node_id' => $peer->id, 'file' => self::peerFile($peer->id),
                'content' => self::section($peer->name, (string) $peer->wireguard_public_key, "{$peer->wireguard_ip}/32")])->values()->all()),
            'prune_argv' => ['find', self::PeerDirectory, '-maxdepth', '1', '-name', '*.conf',
                ...array_merge(...array_map(static fn (int $id): array => ['!', '-name', "{$id}.conf"], $keep)), '-delete'],
            'peer_keys' => ValidatedData::stringList($peers->pluck('wireguard_public_key')->values()->all())];
    }

    /**
     * What a run that removes one Node needs: the shape of a rewrite with nothing to write and only that Node's file to delete. The
     * intent is a snapshot from admission, so a removal that listed the other Nodes would write back, or keep, the peer of a Node that
     * a removal admitted at the same time takes away. The apply step loads the files that remain.
     *
     * @return array{hub_id:int,ssh:array<string,mixed>,port:int,config:string,peers:list<array{node_id:int,file:string,content:string}>,prune_argv:list<string>,peer_keys:list<string>}
     */
    public static function removal(Node $hub, Node $removed): array
    {
        return [...self::intent($hub), 'peers' => [], 'prune_argv' => ['rm', '-f', '--', self::peerFile($removed->id)], 'peer_keys' => []];
    }

    /** The hub's wg-quick file. It holds no peer and no key: it reads the key file, and wg-quick loads every peer file. */
    public static function config(Node $hub): string
    {
        $settings = app(VpnSettings::class);
        $port = filter_var($settings->port(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65_535]]);
        if (! is_int($port) || ! is_string($hub->wireguard_ip)) {
            throw new ResourceOperationException('node.network_unsupported', 'The hub needs its WireGuard address and a valid port.', 422);
        }

        return "[Interface]\nAddress = {$hub->wireguard_ip}/".Ipv4Subnet::from($settings->subnet())->prefixLength()
            ."\nListenPort = {$port}\nPostUp = wg set %i private-key ".self::KeyFile
            ."\nPostUp = for f in ".self::PeerDirectory.'/*.conf; do [ -e "$f" ] && wg addconf %i "$f"; done; true'."\n";
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
