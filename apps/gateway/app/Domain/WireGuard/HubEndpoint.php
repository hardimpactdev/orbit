<?php

declare(strict_types=1);

namespace App\Domain\WireGuard;

use App\Models\Node;

/**
 * The hub endpoint of one Node (ADR 0251). A Node on the hub's private network by the recorded-LAN rule uses the hub's LAN
 * address; every other Node uses the hub's public endpoint. `lan` names what the Node must prove before it writes a
 * WireGuard file that uses the LAN endpoint: the hub's LAN address answers from the Node's, with the hub's recorded SSH host key.
 */
final readonly class HubEndpoint
{
    /** @param array{address:string,source:string,fingerprint:string|null}|null $lan */
    private function __construct(public string $endpoint, public ?array $lan) {}

    public static function for(Node $node, Node $hub, VpnSettings $vpn): self
    {
        // Only an RFC 1918 network: the hub replies to anything else from its public address, so a LAN endpoint there never works.
        if (! $node->is($hub) && LanAddress::isPrivate($hub->lan_ip) && LanAddress::sameNetwork($hub->lan_ip, $node->lan_ip)) {
            return new self(WireGuardEndpoint::format((string) $hub->lan_ip, (int) $vpn->port()), ['address' => (string) $hub->lan_ip, 'source' => (string) $node->lan_ip,
                'fingerprint' => is_string($hub->ssh_host_fingerprint) ? $hub->ssh_host_fingerprint : null]);
        }

        return new self(self::public($hub, $vpn), null);
    }

    /** The address public peers use: the stored Gateway endpoint, or the hub's public SSH host with the WireGuard port. */
    public static function public(Node $hub, VpnSettings $vpn): string
    {
        return $vpn->endpoint() ?? WireGuardEndpoint::format($hub->public_ssh_host, (int) $vpn->port());
    }

    /** The IPv4 address of the public endpoint, which the hub sends its WireGuard packets from; null for a hostname or IPv6. */
    public static function publicAddress(Node $hub, VpnSettings $vpn): ?string
    {
        $endpoint = self::public($hub, $vpn);
        $host = substr($endpoint, 0, (int) strrpos($endpoint, ':'));

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $host : null;
    }
}
