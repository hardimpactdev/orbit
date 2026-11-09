<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\HubEndpoint;
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

    /** Marked hub packets first take the main table without its default route, so private networks keep their route and source. */
    public const int MainRulePriority = 31820;

    /** Then the hub's own table, whose default route sends from the public endpoint address (ADR 0251). */
    public const int SourceRulePriority = 31821;

    /**
     * The hub's wg-quick file and one peer file per Node, from Gateway records. orbit.conf holds no peer: wg-quick loads
     * every file in the peer directory on start. A run that adds one peer writes only that peer's file, so concurrent
     * runs never overwrite each other's peers. Only the files of Nodes that are gone or being removed are pruned: every
     * other Node keeps its file, whatever its status and whether or not the Gateway has recorded its key, because its own run may have written it.
     * The intent is a snapshot and the prune runs minutes later, so it also keeps every file above the newest Node id of the
     * snapshot: Node ids only grow, and a Node created after the snapshot, such as a task VM that joins meanwhile, has a higher one.
     * A gone Node above every remaining one keeps its leftover file until a later converge, after a newer Node exists.
     *
     * @return array{hub_id:int,ssh:array<string,mixed>,port:int,config:string,interface:string,source:array{address:string,mark:int,rules:list<string>,commands:list<string>}|null,peers:list<array{node_id:int,file:string,content:string}>,prune:array{directory:string,keep:list<int>,newest:int},peer_keys:list<string>}
     */
    public static function intent(Node $hub): array
    {
        $peers = Node::query()->whereKeyNot($hub->id)->whereNotNull('wireguard_public_key')->whereNotNull('wireguard_ip')
            ->where('status', '!=', LifecycleStatus::Removing)->orderBy('id')->get();
        $keep = ValidatedData::integerList(Node::query()->whereKeyNot($hub->id)->where('status', '!=', LifecycleStatus::Removing)->orderBy('id')->pluck('id')->all());
        $port = self::port($hub);

        // The hub's private key never leaves the Node: orbit.conf reads its key file, and the apply step composes the key
        // with the peer files on the Node at apply time. No file, the intent nor the run holds it.
        return ['hub_id' => $hub->id, 'ssh' => app(SwampSshExecutionInput::class)->forNode($hub), 'port' => $port,
            'config' => self::config($hub), 'source' => self::source($hub),
            'peers' => array_values($peers->map(static fn (Node $peer): array => ['node_id' => $peer->id, 'file' => self::peerFile($peer->id),
                'content' => self::section($peer->name, (string) $peer->wireguard_public_key, "{$peer->wireguard_ip}/32")])->values()->all()),
            'prune' => ['directory' => self::PeerDirectory, 'keep' => $keep, 'newest' => ValidatedData::integer(Node::query()->max('id'))],
            'peer_keys' => ValidatedData::stringList($peers->pluck('wireguard_public_key')->values()->all())];
    }

    /**
     * What a run that removes one Node needs: the hub's target, file and port, nothing to write, and only that Node's file to delete. The
     * intent is a snapshot from admission, so a removal that listed the other Nodes would write back, or delete, the peer of a Node that
     * another run adds or takes away meanwhile. The apply step loads the files that remain.
     *
     * @return array{hub_id:int,ssh:array<string,mixed>,port:int,config:string,source:array{address:string,mark:int,rules:list<string>,commands:list<string>}|null,peers:list<array{node_id:int,file:string,content:string}>,prune_argv:list<string>,peer_keys:list<string>}
     */
    public static function removal(Node $hub, Node $removed): array
    {
        $intent = self::intent($hub);

        return ['hub_id' => $intent['hub_id'], 'ssh' => $intent['ssh'], 'port' => $intent['port'], 'config' => $intent['config'],
            'peers' => [], 'prune_argv' => ['rm', '-f', '--', self::peerFile($removed->id)], 'peer_keys' => []];
    }

    /**
     * The hub's wg-quick file. It holds no peer and no key: it reads the key file, and wg-quick loads every peer file. It marks
     * the hub's WireGuard packets and, for an IPv4 public endpoint, adds the source rule, so a restart keeps both.
     */
    public static function config(Node $hub): string
    {
        $settings = app(VpnSettings::class);
        $port = self::port($hub);
        $source = self::source($hub);

        return "[Interface]\nAddress = {$hub->wireguard_ip}/".Ipv4Subnet::from($settings->subnet())->prefixLength()
            ."\n".self::interface($port).'PostUp = wg set %i private-key '.self::KeyFile
            ."\nPostUp = for f in ".self::PeerDirectory.'/*.conf; do [ -e "$f" ] && wg addconf %i "$f"; done; true'."\n"
            .implode('', array_map(static fn (string $command): string => "PostUp = {$command}\n", $source['commands'] ?? []));
    }

    /**
     * The interface lines of every `wg syncconf` input on the hub. `syncconf` resets a setting its input leaves out, so the
     * mark travels with the port. The mark is the WireGuard port, and so is the hub's source table.
     */
    public static function interface(int $port): string
    {
        return "ListenPort = {$port}\nFwMark = {$port}\n";
    }

    /**
     * The source rule that makes the hub send every WireGuard packet to a public peer from its public endpoint address (ADR
     * 0251). Null when the public endpoint is a hostname or IPv6: then the hub cannot know the address.
     *
     * @return array{address:string,mark:int,rules:list<string>,commands:list<string>}|null
     */
    public static function source(Node $hub): ?array
    {
        $port = self::port($hub);
        $address = HubEndpoint::publicAddress($hub, app(VpnSettings::class));
        if ($address === null) {
            return null;
        }
        $main = self::MainRulePriority;
        $own = self::SourceRulePriority;

        return ['address' => $address, 'mark' => $port,
            // Each live rule as `ip -j rule show` reports it: priority, hexadecimal mark and table.
            'rules' => [sprintf('%d:0x%x:main', $main, $port), sprintf('%d:0x%x:%d', $own, $port, $port)], 'commands' => [
            "ip -4 rule del priority {$main} 2>/dev/null; ip -4 rule add priority {$main} fwmark {$port} lookup main suppress_prefixlength 0",
            "ip -4 rule del priority {$own} 2>/dev/null; ip -4 rule add priority {$own} fwmark {$port} lookup {$port}",
            // A restart never fails on the route, so the tunnel comes up; the hub converge checks the source and fails loudly.
            'r=$(ip -4 route show default | awk \'{for (i = 1; i < NF; i++) if ($i == "via" || $i == "dev") printf "%s %s ", $i, $(i + 1); exit}\'); '
                ."[ -z \"\$r\" ] || ip -4 route replace default \$r src {$address} table {$port} || true",
        ]];
    }

    private static function port(Node $hub): int
    {
        $port = filter_var(app(VpnSettings::class)->port(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65_535]]);
        if (! is_int($port) || ! is_string($hub->wireguard_ip)) {
            throw new ResourceOperationException('node.network_unsupported', 'The hub needs its WireGuard address and a valid port.', 422);
        }

        return $port;
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
