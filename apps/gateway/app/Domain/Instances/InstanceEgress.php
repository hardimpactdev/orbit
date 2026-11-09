<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Infrastructure\Firewall\UfwManagedRule;
use App\Infrastructure\Firewall\UfwRuleShape;

/**
 * The egress proxy of an Instance container host (docs/reference/development-containers.md#egress-and-dns): Squid, as the fixed
 * user `proxy`, on port 3128 of the Instance bridge's gateway address. It is the containers' one way to the internet: a forward proxy
 * with `CONNECT`, open to every public host and port, and the one place where a rule can narrow that later. Containers get no DNS;
 * the proxy resolves each name through the Node's resolver. Two rules keep it off private services:
 * - Squid refuses a name in the fleet's private routing domains before it looks it up, so no lookup reaches the VPN's DNS.
 * - Its own nftables table rejects every new connection of `proxy` to the Node itself, to the WireGuard interface and to a private
 *   or special address, whatever a name resolved to. It admits only the Node's stub resolver, and only the bridge reaches the port.
 *   This is the only address rule: Squid holds IPv4 as mapped IPv6, so its own address lists cannot state IPv6 ranges safely.
 */
final readonly class InstanceEgress
{
    public const int Port = 3128;

    /** The Ubuntu package's system user; nothing else on a Node runs as it, and the output rule names it. */
    public const string User = 'proxy';

    public const string Directory = '/etc/orbit/instance-egress';

    public const string Unit = 'orbit-instance-egress.service';

    /** The oneshot unit that loads the table early at boot; stopping it deletes the table, and the proxy stops with it. */
    public const string TableUnit = 'orbit-instance-egress-table.service';

    public const string Table = 'orbit_instance_egress';

    public const string Comment = 'orbit:instances-egress';

    /** The Node's WireGuard interface (`wg-quick@orbit`). */
    public const string WireGuardInterface = 'orbit';

    /** systemd-resolved's stub, the Node's resolver: the proxy's only lookup path. */
    public const string Resolver = '127.0.0.53';

    /** IPv4 ranges that are not the public internet: this network, private, CGNAT, loopback, link-local, special, benchmark, multicast and reserved. */
    public const array PrivateV4 = ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24',
        '192.168.0.0/16', '198.18.0.0/15', '224.0.0.0/4', '240.0.0.0/4'];

    /** IPv6 outside global unicast (`::/3` holds loopback, mapped and translated IPv4), and the global ranges that tunnel to IPv4. */
    public const array PrivateV6 = ['::/3', '4000::/2', '8000::/1', '2001::/32', '2002::/16'];

    /**
     * @param  list<string>  $privateDomains  the fleet's private routing domains, which the Node's resolver sends to the VPN's DNS
     * @return array<string, mixed>
     */
    public static function for(string $bridge, string $gateway, string $cidr, array $privateDomains): array
    {
        return [
            'port' => self::Port, 'user' => self::User, 'directory' => self::Directory, 'unit' => self::Unit, 'table_unit' => self::TableUnit,
            'table' => self::Table, 'origin' => self::origin($gateway),
            'squid' => self::squid($gateway, $cidr, $privateDomains),
            'ruleset' => self::ruleset($bridge, $gateway),
            'rule' => self::rule($bridge, $gateway)->shape->toAgentInput(),
        ];
    }

    public static function origin(string $gateway): string
    {
        return 'http://'.$gateway.':'.self::Port;
    }

    /**
     * The proxy settings of everything that runs in a container: units, `incus exec` and login shells (InstanceImage::environment). Lower and upper case, because
     * tools read one or the other; `NODE_USE_ENV_PROXY` makes Node's own `fetch` read them. Only loopback and the bridge's own address,
     * the model forwarder, stay off the proxy.
     *
     * @return array<string, string>
     */
    public static function environment(string $gateway): array
    {
        $origin = self::origin($gateway);
        $direct = 'localhost,127.0.0.1,::1,'.$gateway;

        return ['HTTP_PROXY' => $origin, 'HTTPS_PROXY' => $origin, 'NO_PROXY' => $direct, 'http_proxy' => $origin, 'https_proxy' => $origin,
            'no_proxy' => $direct, 'NODE_USE_ENV_PROXY' => '1'];
    }

    /**
     * Squid as a forward proxy only: no cache, no disk, no headers that name the container, and one access log line per request to
     * the journal, with the container's address. Only the bridge may use it, and a name in a private domain is refused before Squid looks
     * it up. The table refuses private addresses (`ruleset`), so a request for one ends in a 503.
     *
     * @param  list<string>  $privateDomains
     */
    public static function squid(string $gateway, string $cidr, array $privateDomains): string
    {
        $port = self::Port;
        $resolver = self::Resolver;
        $domains = implode(' ', array_map(static fn (string $domain): string => '.'.$domain, $privateDomains));

        return <<<SQUID
            # Managed by Orbit: the Instance egress proxy (/reference/development-containers#egress-and-dns).
            http_port {$gateway}:{$port}
            dns_nameservers {$resolver}
            hosts_file none
            pid_filename none
            netdb_filename none
            pinger_enable off
            visible_hostname orbit-egress
            cache_log /dev/null
            cache deny all
            cache_mem 0 MB
            digest_generation off
            coredump_dir none
            via off
            forwarded_for delete
            httpd_suppress_version_string on
            shutdown_lifetime 2 seconds
            logformat orbit %>a %rm %ru %>Hs %<a %<st
            access_log syslog:daemon.info orbit

            acl bridge src {$cidr}
            acl private_name dstdomain {$domains}
            http_access deny !bridge
            http_access deny private_name
            http_access allow bridge
            http_access deny all

            SQUID;
    }

    /**
     * The proxy's own nftables table. Each chain runs before the `filter` priority, where Docker's and UFW's chains sit, and a reject
     * in any base chain is final.
     * - The input chain resets every connection to the proxy that does not come in on the bridge, so no process on the Node uses it.
     * - The output chain rejects every new connection of `proxy` but to the Node's stub resolver: to any address of the Node itself,
     *   to the WireGuard interface, and to every private or special address. Replies to containers belong to known connections.
     * The file replaces the table whole and atomically, so loading it again leaves no moment without it.
     */
    public static function ruleset(string $bridge, string $gateway): string
    {
        $table = self::Table;
        $user = self::User;
        $port = self::Port;
        $resolver = self::Resolver;
        $wireguard = self::WireGuardInterface;
        $v4 = implode(', ', self::PrivateV4);
        $v6 = implode(', ', self::PrivateV6);

        return <<<NFT
            # Managed by Orbit: the Instance egress proxy (/reference/development-containers#egress-and-dns).
            table inet {$table}
            delete table inet {$table}
            table inet {$table} {
            	set private_v4 {
            		type ipv4_addr
            		flags interval
            		elements = { {$v4} }
            	}
            	set private_v6 {
            		type ipv6_addr
            		flags interval
            		elements = { {$v6} }
            	}
            	chain input {
            		type filter hook input priority filter - 10; policy accept;
            		ip daddr {$gateway} tcp dport {$port} iifname != "{$bridge}" counter reject with tcp reset
            	}
            	chain output {
            		type filter hook output priority filter - 10; policy accept;
            		meta skuid != "{$user}" return
            		ct state != new return
            		ip daddr {$resolver} meta l4proto { tcp, udp } th dport 53 return
            		fib daddr type local counter reject
            		oifname "{$wireguard}" counter reject
            		ip daddr @private_v4 counter reject
            		ip6 daddr @private_v6 counter reject
            	}
            }

            NFT;
    }

    /** Port 3128 in on the Instance bridge only, to its gateway address. */
    public static function rule(string $bridge, string $gateway): UfwManagedRule
    {
        $port = (string) self::Port;

        return new UfwManagedRule(new UfwRuleShape(self::Comment, 'allow', 'in', 'any', $gateway, $port, 'tcp', $bridge, null, 'v4'),
            ['sudo', 'ufw', 'allow', 'in', 'on', $bridge, 'proto', 'tcp', 'from', 'any', 'to', $gateway, 'port', $port, 'comment', self::Comment]);
    }
}
