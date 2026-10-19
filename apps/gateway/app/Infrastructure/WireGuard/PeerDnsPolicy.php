<?php

declare(strict_types=1);

namespace App\Infrastructure\WireGuard;

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RoutePublication;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Domain\WireGuard\VpnSettings;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;

final readonly class PeerDnsPolicy
{
    public const int MAX_DOMAINS = 512;

    public function __construct(private VpnSettings $settings) {}

    public function manages(Node $node): bool
    {
        return $node->platform === 'linux'
            && $node->status === LifecycleStatus::Active
            && $node->roles->isNotEmpty()
            && ! $node->roles->contains('role', RoleName::Vpn)
            && is_string($node->wireguard_ip) && $node->wireguard_ip !== ''
            && is_string($node->wireguard_public_key) && $node->wireguard_public_key !== ''
            && is_string($node->ssh_host_fingerprint) && $node->ssh_host_fingerprint !== '';
    }

    public function server(Node $node, ?string $vpnAddress = null): string
    {
        $vpnAddress ??= Node::query()->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Vpn->value))->first()?->wireguard_ip;
        $address = $node->dns_server_override ?? $this->settings->dnsServer() ?? $vpnAddress;
        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new NodeProvisioningException('wireguard-configuration', 'vpn.configuration_invalid', 'The DNS server is invalid.');
        }
        if (! Ipv4Subnet::from($this->settings->subnet())->containsUsableAddress($address)) {
            throw new NodeProvisioningException('wireguard-configuration', 'vpn.configuration_invalid', 'The private DNS server must be a usable address in the WireGuard subnet.');
        }

        return $address;
    }

    /** @return non-empty-list<string> */
    public function domains(?Node $pendingNode = null): array
    {
        $names = [$this->settings->domain(), 'orbit', $pendingNode?->tld];
        array_push($names, ...Cluster::query()->whereNotNull('tld')->pluck('tld')->all());
        array_push($names, ...Node::query()->whereNotNull('tld')->pluck('tld')->all());
        array_push($names, ...Route::query()->where('publication', RoutePublication::Private)->pluck('domain')->all());
        $domains = [];
        foreach ($names as $name) {
            if ($name === null) {
                continue;
            }
            if (! is_string($name)) {
                throw $this->invalid();
            }
            $domain = strtolower(rtrim($name, '.'));
            if (strlen($domain) > 253 || preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/D', $domain) !== 1) {
                throw $this->invalid();
            }
            $domains[$domain] = $domain;
        }
        // The routing namespace is a set of suffixes, including exact Route names.
        $domains = array_values(array_filter($domains, static fn (string $domain): bool => array_all($domains, static fn (string $parent): bool => $parent === $domain || ! str_ends_with($domain, '.'.$parent))));
        sort($domains, SORT_STRING);
        if ($domains === [] || count($domains) > self::MAX_DOMAINS) {
            throw $this->invalid();
        }

        return $domains;
    }

    private function invalid(): NodeProvisioningException
    {
        return new NodeProvisioningException('private-dns-policy', 'vpn.peer_dns_policy_invalid', 'The private DNS routing domains are invalid or exceed the 512-domain limit.');
    }
}
