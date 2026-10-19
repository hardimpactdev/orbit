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
            && ! $node->roles->contains('role', RoleName::Gateway)
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
    public function domains(Node|Cluster|Route|null $pendingNode = null, bool $removing = false): array
    {
        return $this->renderDomains($pendingNode, $removing, true);
    }

    public function validateChange(Node|Cluster|Route $pending, bool $removing): void
    {
        $proposed = $this->renderDomains($pending, $removing, false);
        if (count($proposed) > self::MAX_DOMAINS && count($proposed) > count($this->renderDomains(null, false, false))) {
            throw $this->invalid();
        }
    }

    /** @return non-empty-list<string> */
    private function renderDomains(Node|Cluster|Route|null $pendingNode, bool $removing, bool $enforceLimit): array
    {
        $names = [$this->settings->domain(), 'orbit'];
        foreach ([Cluster::class, Node::class, Route::class] as $model) {
            $query = $model::query();
            if ($pendingNode instanceof $model && $pendingNode->exists) {
                $query->whereKeyNot($pendingNode->getKey());
            }
            if ($model === Route::class) {
                $query->where('publication', RoutePublication::Private);
            }
            array_push($names, ...$query->whereNotNull($model === Route::class ? 'domain' : 'tld')->pluck($model === Route::class ? 'domain' : 'tld')->all());
        }
        if (! $removing && $pendingNode instanceof Route) {
            if ($pendingNode->publication === RoutePublication::Private) {
                $names[] = $pendingNode->domain;
            }
        } elseif (! $removing && $pendingNode !== null) {
            $names[] = $pendingNode->tld;
        }
        $domains = [];
        foreach ($names as $name) {
            if ($name === null) {
                continue;
            }
            if (! is_string($name) || str_ends_with($name, '..')) {
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
        if ($domains === [] || ($enforceLimit && count($domains) > self::MAX_DOMAINS)) {
            throw $this->invalid();
        }

        return $domains;
    }

    private function invalid(): NodeProvisioningException
    {
        return new NodeProvisioningException('private-dns-policy', 'vpn.peer_dns_policy_invalid', 'The private DNS routing domains are invalid or exceed the 512-domain limit.');
    }
}
