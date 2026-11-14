<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\DatabaseConnections\DockerPublishedPort;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\WebSocket\WebSocketFootprint;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use LogicException;

/**
 * The way to an agent's development servers in the clones of a Node (docs/reference/development-containers.md#agent-development-servers).
 * An nftables table on the Node redirects each new TCP connection that a WireGuard peer opens to a port from 1024 to 65535 of the Node's
 * WireGuard address to Caddy's development listener, and so does a container's connection to the development band of the Instance bridge's
 * gateway address. Caddy then routes by host name, and proxies to the port the client named in the container. Nothing binds the range,
 * so a port in use on the Node never fails a Caddy reload: the WireGuard chain leaves every port that has a listener, and the ports
 * Orbit gives the Node's services, to those services.
 */
final readonly class InstanceDevPorts
{
    /** Every port an agent can open: it runs as `agent`, not root, so it cannot bind a port below 1024. */
    public const int First = 1024;

    public const int Last = 65535;

    /** Caddy's development listener: below the range, so no agent port and no client port meets it. */
    public const int Listener = 444;

    public const string Table = 'orbit_instance_dev_ports';

    public const string TableUnit = 'orbit-instance-dev-ports.service';

    /** The egress proxy of the container egress path (rollout step 2c). */
    public const int EgressProxy = 3128;

    /**
     * The development band of a container on the Instance bridge's gateway address: the Vite preset's port 5173, with room for the
     * next ports Vite moves to when 5173 is taken. The dev-port table redirects exactly this band to Caddy's development listener, and
     * a clone's NIC ACL admits exactly this band (InstanceOwnSite::nicEgress). No Node publication on every address or on the gateway
     * may use it (`assertOutsideBand`), so the redirect never takes a port that Docker publishes there.
     */
    public const string BridgeBand = '5173-5199';

    /** Whether the port lies in the development band. */
    public static function inBand(int $port): bool
    {
        [$first, $last] = array_map(intval(...), explode('-', self::BridgeBand));

        return $port >= $first && $port <= $last;
    }

    /**
     * Refuses a Docker publication of the Node in the development band on every address or on the Instance bridge's gateway address:
     * the bridge redirect would take it. A publication on another address, such as the WireGuard address, never reaches the gateway.
     */
    public static function assertOutsideBand(Node $node, DockerPublishedPort $mapping): void
    {
        $gateway = InstanceHostIntent::gateway($node);
        if (self::inBand($mapping->publishedPort) && in_array($mapping->bindAddress, [null, '0.0.0.0', ...($gateway === null ? [] : [$gateway])], true)) {
            throw new ResourceOperationException('instance.dev_band_reserved',
                'Ports '.self::BridgeBand.' on every address and on the Instance bridge address are the development band of Instance containers. Publish another port, or bind to the WireGuard address.', 422);
        }
    }

    /**
     * The Node's own ports in the range that the table never redirects, even while their service is down: the SSH port, the egress
     * proxy, the published T3 ports, the model guard and forwarder, the WireGuard port, and the ports of the Node's Database servers
     * and Processes. A port that another program listens on is left alone by the table's socket check.
     *
     * @return list<string> Ports and port ranges, in nftables' notation
     */
    public function excluded(Node $node): array
    {
        $ports = self::infrastructurePorts($node);
        foreach (DatabaseServer::query()->where('node_id', $node->id)->get() as $server) {
            $ports[] = (string) $server->port;
        }
        $instances = Instance::query()->where('node_id', $node->id)->pluck('id');
        $processes = Process::query()->where(static fn ($query) => $query->where('owner_type', 'node')->where('owner_id', $node->id))
            ->orWhere(static fn ($query) => $query->whereIn('owner_type', Instance::morphTypes())->whereIn('owner_id', $instances))->get();
        foreach ($processes as $process) {
            $specs = $process->runtime_config['ports'] ?? [];
            foreach (is_array($specs) ? array_filter($specs, is_string(...)) : [] as $spec) {
                // `[address:]host:container[/protocol]`: the Node holds the host port.
                $parts = explode(':', explode('/', $spec)[0]);
                if (count($parts) >= 2) {
                    $ports[] = $parts[count($parts) - 2];
                }
            }
        }
        // An nftables interval set refuses elements that overlap, so overlapping and adjacent ranges merge into one.
        $ranges = [];
        foreach ($ports as $port) {
            if (preg_match('/\A([0-9]{1,5})(?:-([0-9]{1,5}))?\z/D', $port, $match) !== 1) {
                continue;
            }
            $first = max(self::First, (int) $match[1]);
            $last = min(self::Last, (int) ($match[2] ?? $match[1]));
            if ($first <= $last) {
                $ranges[] = [$first, $last];
            }
        }
        usort($ranges, static fn (array $left, array $right): int => $left[0] <=> $right[0]);
        $merged = [];
        foreach ($ranges as [$first, $last]) {
            $previous = array_key_last($merged);
            if ($previous !== null && $first <= $merged[$previous][1] + 1) {
                $merged[$previous][1] = max($merged[$previous][1], $last);
            } else {
                $merged[] = [$first, $last];
            }
        }

        return array_map(static fn (array $range): string => $range[0] === $range[1] ? (string) $range[0] : $range[0].'-'.$range[1], $merged);
    }

    /** @return list<string> Infrastructure ports and ranges, excluding application Processes and Databases. */
    public static function infrastructurePorts(Node $node): array
    {
        return [(string) $node->public_ssh_port, (string) self::EgressProxy, InstanceContainer::T3PortFirst.'-'.InstanceContainer::T3PortLast,
            (InstanceHostIntent::ModelForwarderPort - 1).'-'.InstanceHostIntent::ModelForwarderPort, app(VpnSettings::class)->port(),
            // Reverb's own listener: a container's exact path to it never falls through to the development listener.
            ...($node->roles()->where('role', RoleName::WebSocket->value)->exists() ? [(string) WebSocketFootprint::InstancePort] : [])];
    }

    /**
     * The table, replaced in one transaction. Its WireGuard chain runs after Docker's DNAT, so a port Docker publishes keeps its
     * mapping, and it handles only new connections, so the Node's own connections are untouched. `socket transparent 0` matches a
     * packet that a socket of the Node takes: a port that a program listens on stays with that program. Every other new TCP connection that a
     * WireGuard peer opens to a port of the range on the Node's WireGuard address goes to Caddy's development listener.
     *
     * A container's connection to the development band of the Instance bridge's gateway address takes its own chain, with no
     * exits: every port of the band goes to Caddy's development listener, and only the containers' own sites answer it
     * (RouteCaddySiteSource::ownSites). The chain runs before Docker's address mapping, and no Node publication on the gateway uses
     * the band (`assertOutsideBand`), so the redirect takes no port that another program serves.
     */
    public function ruleset(Node $node): string
    {
        $table = self::Table;
        $interface = InstanceContainer::WireGuardInterface;
        $address = (string) $node->wireguard_ip;
        $bridge = InstanceHostIntent::Network;
        $gateway = InstanceHostIntent::gateway($node) ?? throw new LogicException("Node [{$node->name}] has no Instance bridge.");
        $band = self::BridgeBand;
        $range = self::First.'-'.self::Last;
        $listener = self::Listener;
        $excluded = implode(', ', $this->excluded($node));

        return <<<NFT
            # Managed by Orbit: agent development ports of container clones (/reference/development-containers#agent-development-servers).
            table inet {$table}
            delete table inet {$table}
            table inet {$table} {
            	set excluded {
            		type inet_service
            		flags interval
            		elements = { {$excluded} }
            	}
            	chain prerouting {
            		type nat hook prerouting priority dstnat + 10; policy accept;
            		iifname "{$interface}" ip daddr {$address} tcp dport {$range} ct state new jump development
            	}
            	chain development {
            		tcp dport @excluded return
            		socket transparent 0 return
            		meta l4proto tcp counter redirect to :{$listener}
            	}
            	chain bridge_prerouting {
            		type nat hook prerouting priority dstnat - 10; policy accept;
            		iifname "{$bridge}" ip daddr {$gateway} tcp dport {$band} ct state new jump bridge_development
            	}
            	chain bridge_development {
            		meta l4proto tcp counter redirect to :{$listener}
            	}
            }

            NFT;
    }
}
