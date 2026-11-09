<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\HubEndpoint;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Firewall\UfwManagedRule;
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

    /** Marked hub packets to private ranges first take the main table, so LAN and WireGuard peers keep their route and source. */
    public const int MainRulePriority = 31820;

    /** Then the hub's own table, whose default route sends from the public endpoint address (ADR 0251). */
    public const int SourceRulePriority = 31821;

    /** The script that the hub's source unit runs; Gateway-rendered, like the task VM filter on the hub. */
    public const string SourceScript = '/etc/orbit/wireguard/hub-source';

    public const string SourceUnit = 'orbit-hub-source.service';

    /**
     * The hub's wg-quick file and one peer file per Node, from Gateway records. orbit.conf holds no peer: wg-quick loads
     * every file in the peer directory on start. A run that adds one peer writes only that peer's file, so concurrent
     * runs never overwrite each other's peers. Only the files of Nodes that are gone or being removed are pruned: every
     * other Node keeps its file, whatever its status and whether or not the Gateway has recorded its key, because its own run may have written it.
     * The intent is a snapshot and the prune runs minutes later, so it also keeps every file above the newest Node id of the
     * snapshot: Node ids only grow, and a Node created after the snapshot, such as a task VM that joins meanwhile, has a higher one.
     * A gone Node above every remaining one keeps its leftover file until a later converge, after a newer Node exists.
     *
     * @return array{hub_id:int,ssh:array<string,mixed>,port:int,config:string,interface:string,source:array{address:string,mark:int,rules:list<string>,script_path:string,script:string,unit_path:string,unit_name:string,unit:string}|null,lan_ssh_argv:list<list<string>>,peers:list<array{node_id:int,file:string,content:string}>,prune:array{directory:string,keep:list<int>,newest:int},peer_keys:list<string>}
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
            'config' => self::config($hub), 'interface' => self::interface($port), 'source' => self::source($hub),
            'lan_ssh_argv' => array_map(static fn (UfwManagedRule $rule): array => array_slice($rule->arguments, 1),
                app(NodeFirewallRuleCatalog::class)->vpnLanSsh($hub)),
            'peers' => array_values($peers->map(static fn (Node $peer): array => ['node_id' => $peer->id, 'file' => self::peerFile($peer->id),
                'content' => self::section($peer->name, (string) $peer->wireguard_public_key, "{$peer->wireguard_ip}/32")])->values()->all()),
            'prune' => ['directory' => self::PeerDirectory, 'keep' => $keep, 'newest' => ValidatedData::integer(Node::query()->max('id'))],
            'peer_keys' => ValidatedData::stringList($peers->pluck('wireguard_public_key')->values()->all())];
    }

    /**
     * What a run that removes one Node needs: the hub's target, file, port and interface lines, nothing to write, and only that Node's file to delete. The
     * intent is a snapshot from admission, so a removal that listed the other Nodes would write back, or delete, the peer of a Node that
     * another run adds or takes away meanwhile. The apply step loads the files that remain.
     *
     * @return array{hub_id:int,ssh:array<string,mixed>,port:int,config:string,interface:string,source:array{address:string,mark:int,rules:list<string>,script_path:string,script:string,unit_path:string,unit_name:string,unit:string}|null,peers:list<array{node_id:int,file:string,content:string}>,prune_argv:list<string>,peer_keys:list<string>}
     */
    public static function removal(Node $hub, Node $removed): array
    {
        $intent = self::intent($hub);

        return ['hub_id' => $intent['hub_id'], 'ssh' => $intent['ssh'], 'port' => $intent['port'], 'config' => $intent['config'],
            'interface' => $intent['interface'], 'source' => $intent['source'], 'peers' => [], 'prune_argv' => ['rm', '-f', '--', self::peerFile($removed->id)], 'peer_keys' => []];
    }

    /**
     * The hub's wg-quick file. It holds no peer and no key: it reads the key file, and wg-quick loads every peer file. It marks
     * the hub's WireGuard packets; the source rule for marked packets lives in its own unit, which no writer of this file touches.
     */
    public static function config(Node $hub): string
    {
        return "[Interface]\nAddress = {$hub->wireguard_ip}/".Ipv4Subnet::from(app(VpnSettings::class)->subnet())->prefixLength()
            ."\n".self::interface(self::port($hub)).'PostUp = wg set %i private-key '.self::KeyFile
            ."\nPostUp = for f in ".self::PeerDirectory.'/*.conf; do [ -e "$f" ] && wg addconf %i "$f"; done; true'."\n";
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
     * 0251), as one Orbit-owned unit: a script that sets the rules and the table, and the oneshot unit that runs it before
     * `wg-quick@orbit`. Marked packets to private ranges keep the main table; every other marked packet, the hub's own public
     * prefix included, takes the hub's table, whose default route leaves from the endpoint address. The unit is wanted by the
     * tunnel, never required, so a failing rule cannot keep the tunnel down. Null when the public endpoint is a hostname or
     * IPv6: then the hub cannot know the address.
     *
     * @return array{address:string,mark:int,rules:list<string>,script_path:string,script:string,unit_path:string,unit_name:string,unit:string}|null
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
        $private = self::privateRanges();
        $mark = sprintf('0x%x', $port);

        return ['address' => $address, 'mark' => $port,
            // Each rule as `ip -j rule show` reports it: priority, hexadecimal mark, destination and table.
            'rules' => [...array_map(static fn (string $range): string => "{$main}:{$mark}:{$range}:main", $private), "{$own}:{$mark}:all:{$port}"],
            'script_path' => self::SourceScript, 'unit_path' => '/etc/systemd/system/'.self::SourceUnit, 'unit_name' => self::SourceUnit,
            'script' => implode("\n", [
                '#!/bin/sh',
                '# Managed by Orbit (ADR 0251): the hub sends WireGuard packets to public peers from '.$address.'.',
                'set -eu',
                "while ip -4 rule del priority {$main} 2>/dev/null; do :; done",
                "while ip -4 rule del priority {$own} 2>/dev/null; do :; done",
                "ip -4 route flush table {$port} 2>/dev/null || true",
                ...array_map(static fn (string $range): string => "ip -4 rule add priority {$main} fwmark {$port} to {$range} lookup main", $private),
                "ip -4 rule add priority {$own} fwmark {$port} lookup {$port}",
                '# The table copies the first default route of the main table and leaves from the endpoint address.',
                'set -- $(ip -4 route show default | head -n 1)',
                'route=',
                'while [ "$#" -gt 0 ]; do',
                '  case "$1" in',
                '    via|dev) [ "$#" -gt 1 ] && route="$route $1 $2" && shift ;;',
                '    onlink) route="$route onlink" ;;',
                '  esac',
                '  shift',
                'done',
                "ip -4 route replace default \$route src {$address} table {$port}",
            ])."\n",
            // systemd-networkd deletes foreign rules and routes when it starts, so the unit runs again after every start of it.
            'unit' => "[Unit]\nDescription=Orbit hub WireGuard source address\nWants=network-online.target\n"
                ."After=network-online.target systemd-networkd.service\nPartOf=systemd-networkd.service\nBefore=wg-quick@orbit.service\n\n[Service]\nType=oneshot\nRemainAfterExit=yes\nExecStart=/bin/sh ".self::SourceScript
                ."\n\n[Install]\nWantedBy=wg-quick@orbit.service systemd-networkd.service multi-user.target\n"];
    }

    /** RFC 1918 and the WireGuard subnet when it lies outside them: the destinations that keep the main table. @return list<string> */
    private static function privateRanges(): array
    {
        $ranges = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];
        $subnet = Ipv4Subnet::from(app(VpnSettings::class)->subnet());
        foreach ($ranges as $range) {
            if (Ipv4Subnet::from($range)->contains($subnet->networkAddress())) {
                return $ranges;
            }
        }

        return [...$ranges, $subnet->value()];
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
