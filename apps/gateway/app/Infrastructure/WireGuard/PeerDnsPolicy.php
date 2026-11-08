<?php

declare(strict_types=1);

namespace App\Infrastructure\WireGuard;

use App\Domain\AppDev\ClusterTldTargets;
use App\Domain\Clusters\ClusterDnsTransition;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteRenderContext;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;

final readonly class PeerDnsPolicy
{
    public const int MAX_DOMAINS = 512;

    public function __construct(private VpnSettings $settings) {}

    /** A private name every managed peer resolves through the `orbit` link; peer DNS ends by resolving it. */
    public const string PRIVATE_PROBE = 'gateway.orbit';

    public function manages(Node $node): bool
    {
        return $node->platform === 'linux'
            && $node->status === LifecycleStatus::Active
            && ($node->roles->isNotEmpty() || app(RouteRenderContext::class)->clusterId($node) !== null || isset($node->settings[ClusterDnsTransition::Key]))
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

    /**
     * Desired scopes, before capability detection on the member. Unsupported resolved keeps
     * all_domains. Never put a VPN second server in the Cluster delegate.
     *
     * @return array{server:string, domains:list<string>, all_domains:list<string>, delegate:?array{cluster_id:int, router_id:int, server:string, domains:list<string>}}
     */
    public function selection(Node $node): array
    {
        $all = $this->domains();
        $context = app(RouteRenderContext::class);
        $delegate = null;
        $transition = $node->settings[ClusterDnsTransition::Key] ?? null;
        $cluster = is_array($transition)
            ? (is_int($transition['target_cluster_id'] ?? null) ? Cluster::query()->find($transition['target_cluster_id']) : null)
            : Cluster::query()->find($context->clusterId($node));
        if ($cluster !== null && $context->lanIp($node) !== null) {
            $pending = $cluster->pendingDns();
            $active = ($pending['state'] ?? $cluster->state->value) === 'active';
            $router = app(ClusterTldTargets::class)->router($cluster, $pending);
            $routerLan = $router !== null ? $context->lanIp($router) : null;
            if ($active && $router !== null && $routerLan !== null) {
                if ($node->dns_server_override !== null) {
                    throw new NodeProvisioningException('private-dns-policy', 'vpn.peer_dns_policy_invalid', 'A Cluster DNS delegate conflicts with the explicit DNS override.');
                }
                $overlay = app(DevelopmentDnsConfigRenderer::class)->routerState($cluster, $router);
                $owned = Route::query()->where('cluster_id', $cluster->id)->where('publication', RoutePublication::Private)
                    ->pluck('domain')->all();
                $tld = array_key_exists('tld', $pending) ? $pending['tld'] : $cluster->tld;
                $domains = array_values(array_unique(array_filter([$tld, ...$owned], is_string(...))));
                // Minimize each destination scope separately. Preserve foreign exceptions on VPN.
                $domains = self::suffixes($domains);
                if ($domains !== []) {
                    $delegate = ['cluster_id' => $cluster->id, 'router_id' => $router->id, 'server' => (string) $routerLan, 'domains' => $domains];
                    $vpn = array_values(array_filter($all, static fn (string $name): bool => ! in_array($name, $domains, true)));
                    foreach ($overlay->tlds as $name => $address) {
                        if ($address !== $routerLan) {
                            $vpn[] = (string) $name;
                        }
                    }
                    foreach ($overlay->records as $name => $address) {
                        if ($address !== $routerLan) {
                            $vpn[] = (string) $name;
                        }
                    }
                    $all = self::suffixes([...$all, ...$vpn, ...$domains]);
                }
            }
        }

        return ['server' => $this->server($node), 'domains' => self::suffixes($vpn ?? $all), 'all_domains' => $all, 'delegate' => $delegate];
    }

    /** @param list<string> $names
     * @return list<string>
     */
    private static function suffixes(array $names): array
    {
        $names = array_values(array_unique($names));
        $names = array_values(array_filter($names, static fn (string $name): bool => array_all($names,
            static fn (string $parent): bool => $name === $parent || ! str_ends_with($name, '.'.$parent))));
        sort($names);

        return $names;
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
