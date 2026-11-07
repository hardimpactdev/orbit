<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\Analytics\AnalyticsHostname;
use App\Domain\AppDev\ClusterRouterDnsSelection;
use App\Domain\AppDev\PrivateDnsState;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\ClusterRouterTransition;
use App\Domain\Routes\ContributedPrivateHostnames;
use App\Domain\Routes\ReservedPrivateHostname;
use App\Domain\Routes\RouteRenderContext;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WebSocket\WebSocketHostname;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\WebSocket\WebSocketDnsTarget;
use App\Models\Node;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Database\Eloquent\Builder;

final readonly class DevelopmentDnsConfigRenderer
{
    /** @param iterable<ContributedPrivateHostnames> $hostnames */
    public function __construct(
        private DevelopmentSiteRepository $sites,
        #[Tag(ContributedPrivateHostnames::class)] private iterable $hostnames,
        private ClusterRouterDnsSelection $selection = new ClusterRouterDnsSelection,
        private ClusterRouterTransition $routerTransitions = new ClusterRouterTransition,
        private WebSocketDnsTarget $websocket = new WebSocketDnsTarget,
        private ?VpnSettings $vpn = null,
    ) {}

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState, tld?: ?string, router_node_id?: ?int}>  $clusterOverrides
     */
    public function render(
        ?Node $pendingNode = null,
        array $nodeOverrides = [],
        array $clusterOverrides = [],
    ): string {
        $collected = $this->collect($pendingNode, $nodeOverrides, $clusterOverrides);
        $records = collect();

        foreach ($collected['tlds'] as $tld => $address) {
            $records->push("local=/{$tld}/");
            $records->push("address=/.{$tld}/{$address}");
        }

        foreach ($collected['localOnly'] as $tld) {
            $records->push("local=/{$tld}/");
        }

        foreach ($collected['hosts'] as $name => $address) {
            $records->push("host-record={$name},{$address}");
        }

        return '# Managed by Orbit.'.PHP_EOL.$records->unique()->sort()->implode(PHP_EOL).PHP_EOL;
    }

    /**
     * The complete private DNS as data, read from stored rows: the phase on the vpn Node writes it.
     *
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState, tld?: ?string, router_node_id?: ?int}>  $clusterOverrides
     */
    public function state(
        ?Node $pendingNode = null,
        array $nodeOverrides = [],
        array $clusterOverrides = [],
    ): PrivateDnsState {
        $collected = $this->collect($pendingNode, $nodeOverrides, $clusterOverrides);

        return PrivateDnsState::from(
            listen: $this->listen($pendingNode),
            tlds: $collected['tlds'],
            hosts: $collected['hosts'],
        );
    }

    /** @return list<string> The address that VPN DNS answers on: the configured one, else the VPN Node's. */
    private function listen(?Node $pendingNode): array
    {
        $configured = ($this->vpn ?? app(VpnSettings::class))->dnsServer();

        if (is_string($configured) && $configured !== '') {
            return [DnsAddress::normalize($configured) ?? $configured];
        }

        $holder = $this->roleNode(RoleName::Vpn, $pendingNode) ?? $this->roleNode(RoleName::Gateway, $pendingNode);
        $address = $holder instanceof Node ? app(RouteRenderContext::class)->wireguardIp($holder) : null;

        return is_string($address) && $address !== '' ? [DnsAddress::normalize($address) ?? $address] : [];
    }

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState, tld?: ?string, router_node_id?: ?int}>  $clusterOverrides
     * @return array{tlds: array<string, string>, localOnly: list<string>, hosts: array<string, string>}
     */
    private function collect(?Node $pendingNode, array $nodeOverrides, array $clusterOverrides): array
    {
        $nodes = Node::query()
            ->where(static function (Builder $q) use ($pendingNode): void {
                $q->where('status', LifecycleStatus::Active->value)
                    ->orWhereKey(app(RouteRenderContext::class)->nodeId() ?? 0);
                if (
                    $pendingNode instanceof Node
                    && $pendingNode->exists
                    && $pendingNode->status === LifecycleStatus::Provisioning
                ) {
                    $q->orWhere('id', $pendingNode->id);
                }
            })
            ->whereHas('roles', static fn (Builder $q): Builder => $q->where(
                'role',
                RoleName::AppDev->value,
            )->whereIn('status', [LifecycleStatus::Provisioning->value, LifecycleStatus::Active->value]))
            ->get();
        $clusterTargets = $this->selection->clusterTldTargets($nodeOverrides, $clusterOverrides);
        $tlds = [];
        $localOnly = [];

        foreach ($nodes as $node) {
            $identity = app(RouteRenderContext::class);
            $tld = $identity->tld($node);
            $address = $identity->wireguardIp($node);

            if ($tld === null || $address === null) {
                continue;
            }

            $tld = rtrim(strtolower($tld), '.');

            if (array_key_exists($tld, $clusterTargets)) {
                $localOnly[] = $tld;

                continue;
            }

            $tlds[$tld] = $address;
        }

        // A Cluster TLD answers with its Router and wins over a Node that owns the same TLD.
        $tlds = [...$tlds, ...$clusterTargets];
        // A Router selection names the Router Node that answers: a selection being published or
        // restored, else a stored Router replacement that has published DNS.
        $selectedRouters = array_values(array_replace(
            $this->routerTransitions->dnsRouters(),
            $this->routerOverrides($clusterOverrides),
        ));
        $hosts = [];

        foreach ($this->sites->all()->groupBy('domain') as $sites) {
            $record = $this->hostRecord(array_values($sites->all()), $selectedRouters);

            if ($record !== null) {
                $hosts[$record['domain']] = $record['address'];
            }
        }

        // `node:role:add gateway gateway --converge` marks the singleton assignment provisioning while
        // it republishes DNS, so a converging holder keeps gateway.orbit; an active holder wins.
        $gateway = null;
        foreach ([LifecycleStatus::Active, LifecycleStatus::Provisioning] as $gatewayStatus) {
            $gateway ??= Node::query()
                ->where(static function (Builder $query): void {
                    $query->where('status', LifecycleStatus::Active->value);
                    $candidate = app(RouteRenderContext::class)->enrollment();
                    if ($candidate !== null) {
                        $query->orWhereKey($candidate->nodeId);
                    }
                })
                ->where(static fn (Builder $query): Builder => $query->whereNotNull('wireguard_ip')
                    ->orWhereKey(app(RouteRenderContext::class)->nodeId() ?? 0))
                ->whereHas('roles', static fn (Builder $q): Builder => $q->where('role', RoleName::Gateway->value)->where(
                    'status',
                    $gatewayStatus->value,
                ))
                ->first();
        }
        if ($gateway instanceof Node) {
            $address = app(RouteRenderContext::class)->wireguardIp($gateway);
            $hosts[ReservedPrivateHostname::Gateway] = $address;

            if ($this->roleNode(RoleName::Metrics, $pendingNode) instanceof Node) {
                $hosts[ReservedPrivateHostname::Metrics] = $address;
            }
        }

        $websocket = $this->websocket->node($this->roleNode(RoleName::WebSocket, $pendingNode));

        if ($websocket instanceof Node) {
            $hosts[WebSocketHostname::Value] = app(RouteRenderContext::class)->wireguardIp($websocket);
        }

        $analytics = $this->roleNode(RoleName::Analytics, $pendingNode);

        if ($analytics instanceof Node) {
            $hosts[AnalyticsHostname::Value] = app(RouteRenderContext::class)->wireguardIp($analytics);
        }

        foreach ($this->hostnames as $contribution) {
            foreach ($contribution->hostnames() as $hostname => $address) {
                if ($address !== null) {
                    $hosts[$hostname] = $address;
                }
            }
        }

        return ['tlds' => $tlds, 'localOnly' => array_values(array_unique($localOnly)), 'hosts' => $hosts];
    }

    /**
     * The Node that holds a singleton role. `node:role:add NODE ROLE --converge` marks the assignment
     * provisioning while it republishes DNS, so a converging holder keeps its record; an active holder
     * wins. A pending Node counts while it is still being added.
     */
    private function roleNode(RoleName $role, ?Node $pendingNode): ?Node
    {
        $node = null;

        foreach ([LifecycleStatus::Active, LifecycleStatus::Provisioning] as $status) {
            $node ??= Node::query()
                ->where(static function (Builder $q) use ($pendingNode): void {
                    $q->where('status', LifecycleStatus::Active->value)
                        ->orWhereKey(app(RouteRenderContext::class)->nodeId() ?? 0);

                    if ($pendingNode instanceof Node && $pendingNode->exists) {
                        $q->orWhere('id', $pendingNode->id);
                    }
                })
                ->where(static fn (Builder $query): Builder => $query->whereNotNull('wireguard_ip')
                    ->orWhereKey(app(RouteRenderContext::class)->nodeId() ?? 0))
                ->whereHas('roles', static fn (Builder $q): Builder => $q
                    ->where('role', $role->value)
                    ->where('status', $status->value))
                ->first();
        }

        return $node;
    }

    /**
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState, tld?: ?string, router_node_id?: ?int}>  $clusterOverrides
     */
    public function catalog(
        ?Node $pendingNode = null,
        array $nodeOverrides = [],
        array $clusterOverrides = [],
    ): PrivateDnsAnswerCatalog {
        $catalog = PrivateDnsAnswerCatalog::fromDnsmasqConfiguration(
            $this->render($pendingNode, $nodeOverrides, $clusterOverrides),
        );
        $answers = $this->selection->answers($nodeOverrides, $clusterOverrides);
        $overrides = $catalog->overrides;

        foreach ($answers['overrides'] as $cacheKey => $requesterOverrides) {
            $overrides[$cacheKey] = [
                ...($overrides[$cacheKey] ?? []),
                ...$requesterOverrides,
            ];
        }

        return new PrivateDnsAnswerCatalog(
            exact: $catalog->exact,
            suffixes: [
                ...$catalog->suffixes,
                ...$answers['suffixes'],
            ],
            overrides: $overrides,
        );
    }

    /**
     * A domain answers with its Router, else its workload. A Router selection that is being
     * published or restored names the Router Node that answers; otherwise a site that serves a
     * second placement or a second Router never answers while the current site exists.
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

    /**
     * @param  array<int, array{state?: ClusterState, tld?: ?string, router_node_id?: ?int}>  $clusterOverrides
     * @return array<int, int>
     */
    private function routerOverrides(array $clusterOverrides): array
    {
        $overrides = [];

        foreach ($clusterOverrides as $clusterId => $override) {
            if (array_key_exists('router_node_id', $override) && is_int($override['router_node_id'])) {
                $overrides[(int) $clusterId] = $override['router_node_id'];
            }
        }

        return $overrides;
    }

    /** @return array<string, int> */
    public function registeredRequesters(): array
    {
        $requesters = [];

        Node::query()
            ->where(static fn (Builder $query): Builder => $query->where('status', LifecycleStatus::Active->value)
                ->orWhereKey(app(RouteRenderContext::class)->nodeId() ?? 0))
            ->where(static fn (Builder $query): Builder => $query->whereNotNull('wireguard_ip')
                ->orWhereKey(app(RouteRenderContext::class)->nodeId() ?? 0))
            ->orderBy('id')
            ->get()
            ->each(static function (Node $node) use (&$requesters): void {
                $address = DnsAddress::normalize((string) app(RouteRenderContext::class)->wireguardIp($node));
                if ($address === null) {
                    return;
                }

                $requesters[$address] = $node->id;
            });

        return $requesters;
    }
}
