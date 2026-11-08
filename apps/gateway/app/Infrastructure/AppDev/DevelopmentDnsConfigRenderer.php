<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\Analytics\AnalyticsHostname;
use App\Domain\AppDev\ClusterTldTargets;
use App\Domain\AppDev\PrivateDnsState;
use App\Domain\Clusters\ClusterDnsEvidence;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\ClusterRouterTransition;
use App\Domain\Routes\ContributedPrivateHostnames;
use App\Domain\Routes\ReservedPrivateHostname;
use App\Domain\Routes\RouteRenderContext;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WebSocket\WebSocketHostname;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\WebSocket\WebSocketDnsTarget;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Route;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Database\Eloquent\Builder;

/** Renders the complete private DNS as data from stored rows. */
final readonly class DevelopmentDnsConfigRenderer
{
    /** @param iterable<ContributedPrivateHostnames> $hostnames */
    public function __construct(
        private DevelopmentSiteRepository $sites,
        #[Tag(ContributedPrivateHostnames::class)] private iterable $hostnames,
        private ClusterTldTargets $clusters = new ClusterTldTargets,
        private ClusterRouterTransition $routerTransitions = new ClusterRouterTransition,
        private WebSocketDnsTarget $websocket = new WebSocketDnsTarget,
        private ?VpnSettings $vpn = null,
    ) {}

    public function state(): PrivateDnsState
    {
        $tlds = [];

        foreach ($this->appDevNodes() as $node) {
            $identity = app(RouteRenderContext::class);
            $tld = $identity->tld($node);
            $address = $identity->wireguardIp($node);

            if ($tld !== null && $address !== null) {
                $tlds[rtrim(strtolower($tld), '.')] = $address;
            }
        }

        // A Cluster TLD answers with its Router and wins over a Node that owns the same TLD.
        $tlds = [...$tlds, ...$this->clusters->all()];
        // A stored Router replacement that has published DNS answers for its Cluster's Routes.
        $selectedRouters = array_values($this->routerTransitions->dnsRouters());
        $hosts = [];

        foreach ($this->sites->all()->groupBy('domain') as $sites) {
            $record = $this->hostRecord(array_values($sites->all()), $selectedRouters);

            if ($record !== null) {
                $hosts[$record['domain']] = $record['address'];
            }
        }

        $gateway = $this->roleNode(RoleName::Gateway);

        $address = $gateway instanceof Node ? app(RouteRenderContext::class)->wireguardIp($gateway) : null;

        if ($address !== null) {
            $hosts[ReservedPrivateHostname::Gateway] = $address;

            if ($this->roleNode(RoleName::Metrics) instanceof Node) {
                $hosts[ReservedPrivateHostname::Metrics] = $address;
            }
        }

        $vpn = $this->roleNode(RoleName::Vpn);
        $address = $vpn instanceof Node ? app(RouteRenderContext::class)->wireguardIp($vpn) : null;

        if ($address !== null) {
            $hosts[ReservedPrivateHostname::Vpn] = $address;
        }

        $websocket = $this->websocket->node($this->roleNode(RoleName::WebSocket));
        $address = $websocket instanceof Node ? app(RouteRenderContext::class)->wireguardIp($websocket) : null;

        if ($address !== null) {
            $hosts[WebSocketHostname::Value] = $address;
        }

        $analytics = $this->roleNode(RoleName::Analytics);
        $address = $analytics instanceof Node ? app(RouteRenderContext::class)->wireguardIp($analytics) : null;

        if ($address !== null) {
            $hosts[AnalyticsHostname::Value] = $address;
        }

        foreach ($this->hostnames as $contribution) {
            foreach ($contribution->hostnames() as $hostname => $address) {
                if ($address !== null) {
                    $hosts[$hostname] = $address;
                }
            }
        }

        return PrivateDnsState::from(listen: $this->listen(), tlds: $tlds, hosts: $hosts);
    }

    /**
     * The Cluster overlay uses the same selected canonical records. Keep foreign exact names and
     * nested namespaces beneath its wildcard, so a LAN wildcard cannot capture another target.
     */
    public function routerState(Cluster $cluster, Node $router): PrivateDnsState
    {
        $canonical = $this->state();
        $lan = app(RouteRenderContext::class)->lanIp($router);
        $pending = $cluster->pendingDns();
        $tld = array_key_exists('tld', $pending) ? $pending['tld'] : $cluster->tld;
        $tlds = [];
        $records = [];
        if (is_string($tld) && $tld !== '') {
            $tlds[$tld] = (string) $lan;
            foreach ($canonical->tlds as $name => $address) {
                if (str_ends_with((string) $name, '.'.$tld)) {
                    $tlds[$name] = $address;
                }
            }
        }
        $owned = Route::query()->where('cluster_id', $cluster->id)
            ->where('publication', 'private')->pluck('domain')->all();
        foreach ($canonical->records as $name => $address) {
            if (in_array($name, $owned, true)) {
                $records[$name] = (string) $lan;
            } elseif (array_any(array_filter([$tld, ...$owned], is_string(...)), static fn (string $scope): bool => $name === $scope || str_ends_with((string) $name, '.'.$scope))) {
                $records[$name] = $address;
            }
        }

        $listen = [(string) $lan];
        foreach (Node::query()->where('settings->'.ClusterDnsEvidence::Key.'->effective->delegate->router_id', $router->id)->get() as $member) {
            $previous = $member->settings[ClusterDnsEvidence::Key]['effective']['delegate'] ?? null;
            if (is_array($previous) && ($previous['cluster_id'] ?? null) === $cluster->id
                && is_string($previous['server'] ?? null) && $previous['server'] !== $lan) {
                $listen[] = $previous['server'];
            }
        }

        return PrivateDnsState::from(array_values(array_unique($listen)), $tlds, $records);
    }

    /** @return list<string> The address that VPN DNS answers on: the configured one, else the VPN Node's. */
    private function listen(): array
    {
        $configured = ($this->vpn ?? app(VpnSettings::class))->dnsServer();

        if (is_string($configured) && $configured !== '') {
            return [DnsAddress::normalize($configured) ?? $configured];
        }

        $holder = $this->roleNode(RoleName::Vpn) ?? $this->roleNode(RoleName::Gateway);
        $address = $holder instanceof Node ? app(RouteRenderContext::class)->wireguardIp($holder) : null;

        return is_string($address) && $address !== '' ? [DnsAddress::normalize($address) ?? $address] : [];
    }

    /** @return iterable<Node> */
    private function appDevNodes(): iterable
    {
        return Node::query()
            ->whereIn('status', [LifecycleStatus::Active->value, LifecycleStatus::Provisioning->value])
            ->whereHas('roles', static fn (Builder $q): Builder => $q->where('role', RoleName::AppDev->value)
                ->whereIn('status', [LifecycleStatus::Provisioning->value, LifecycleStatus::Active->value]))
            ->get();
    }

    /**
     * The Node that holds a singleton role. `node:role:add NODE ROLE --converge` marks the assignment
     * provisioning while it republishes DNS, so a converging holder keeps its record; an active holder
     * wins.
     */
    private function roleNode(RoleName $role): ?Node
    {
        $node = null;

        foreach ([LifecycleStatus::Active, LifecycleStatus::Provisioning] as $status) {
            $node ??= Node::query()
                ->whereIn('status', [LifecycleStatus::Active->value, LifecycleStatus::Provisioning->value])
                ->whereNotNull('wireguard_ip')
                ->whereHas('roles', static fn (Builder $q): Builder => $q
                    ->where('role', $role->value)
                    ->where('status', $status->value))
                ->first();
        }

        return $node;
    }

    /**
     * A domain answers with its Router, else its workload. A stored Router replacement that has published
     * DNS names the Router Node that answers; otherwise a site that serves a second placement or a second
     * Router never answers while the current site exists.
     *
     * @param  list<DevelopmentSite>  $sites
     * @param  list<int>  $selectedRouters
     * @return array{domain: string, address: string}|null
     */
    private function hostRecord(array $sites, array $selectedRouters): ?array
    {
        $sites = collect($sites);
        $selected = $sites->filter(static fn (DevelopmentSite $site): bool => in_array($site->nodeId, $selectedRouters, true));
        $current = $sites->reject(static fn (DevelopmentSite $site): bool => $site->secondary);
        $site = $selected->first(static fn (DevelopmentSite $candidate): bool => $candidate->isProxy())
            ?? $selected->first()
            ?? $current->first(static fn (DevelopmentSite $candidate): bool => $candidate->isProxy())
            ?? $current->first()
            ?? $sites->first();

        if (! $site instanceof DevelopmentSite) {
            return null;
        }

        return ['domain' => strtolower(rtrim($site->domain, '.')), 'address' => $site->nodeAddress];
    }
}
