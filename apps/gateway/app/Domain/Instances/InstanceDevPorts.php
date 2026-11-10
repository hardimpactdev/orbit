<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\WireGuard\VpnSettings;
use App\Models\DatabaseServer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;

/**
 * The way to an agent's development servers in the clones of a Node (docs/reference/development-containers.md#agent-development-servers).
 * An nftables table on the Node redirects each new TCP connection that a WireGuard peer opens to a port from 1024 to 65535 of the Node's
 * WireGuard address to Caddy's development listener. Caddy then routes by host name, and proxies to the port the client named in the
 * container. Nothing binds the range, so a port in use on the Node never fails a Caddy reload: the table leaves every port that has
 * a listener, and the ports Orbit gives the Node's services, to those services.
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
     * The Node's own ports in the range that the table never redirects, even while their service is down: the SSH port, the egress
     * proxy, the published T3 ports, the model guard and forwarder, the WireGuard port, and the ports of the Node's Database servers
     * and Processes. A port that another program listens on is left alone by the table's socket check.
     *
     * @return list<string> Ports and port ranges, in nftables' notation
     */
    public function excluded(Node $node): array
    {
        $ports = [(string) $node->public_ssh_port, (string) self::EgressProxy, InstanceContainer::T3PortFirst.'-'.InstanceContainer::T3PortLast,
            (InstanceHostIntent::ModelForwarderPort - 1).'-'.InstanceHostIntent::ModelForwarderPort, app(VpnSettings::class)->port()];
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
        $ports = array_values(array_filter(array_unique($ports), static function (string $port): bool {
            $first = (int) explode('-', $port)[0];

            return preg_match('/\A[0-9]{1,5}(?:-[0-9]{1,5})?\z/D', $port) === 1 && (int) (explode('-', $port)[1] ?? $first) >= self::First;
        }));
        sort($ports, SORT_NATURAL);

        return $ports;
    }

    /**
     * The table, replaced in one transaction. It runs after Docker's DNAT, so a port Docker publishes keeps its mapping, and it
     * handles only new connections, so the Node's own connections are untouched. `socket transparent 0` matches a packet that a
     * socket of the Node takes: a port that a program listens on stays with that program. Every other new TCP connection that a
     * WireGuard peer opens to a port of the range on the Node's WireGuard address goes to Caddy's development listener.
     */
    public function ruleset(Node $node): string
    {
        $table = self::Table;
        $interface = InstanceContainer::WireGuardInterface;
        $address = (string) $node->wireguard_ip;
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
            }

            NFT;
    }
}
