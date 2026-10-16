<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\Analytics\AnalyticsHostname;
use App\Domain\AppDev\ClusterRouterDnsSelection;
use App\Domain\AppDev\ContributedPrivateDnsRecords;
use App\Domain\Clusters\ClusterState;
use App\Domain\Extensions\Contributions;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\ClusterRouterTransition;
use App\Domain\Routes\ReservedPrivateHostname;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WebSocket\WebSocketHostname;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\WebSocket\WebSocketDnsTarget;
use App\Models\Node;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

final readonly class DevelopmentDnsConfigRenderer
{
    public function __construct(
        private DevelopmentSiteRepository $sites,
        private ClusterRouterDnsSelection $selection = new ClusterRouterDnsSelection,
        private ClusterRouterTransition $routerTransitions = new ClusterRouterTransition,
        private WebSocketDnsTarget $websocket = new WebSocketDnsTarget,
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
        $nodes = Node::query()
            ->where(static function (Builder $q) use ($pendingNode): void {
                $q->where('status', LifecycleStatus::Active->value);
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
            ->whereNotNull('wireguard_ip')
            ->whereNotNull('tld')
            ->get();
        $clusterTlds = $this->selection->clusterTlds($nodeOverrides, $clusterOverrides);
        $nodes = $nodes
            ->flatMap(static function (Node $n) use ($clusterTlds): array {
                $records = ["local=/{$n->tld}/"];

                if (! in_array(rtrim(strtolower((string) $n->tld), '.'), $clusterTlds, true)) {
                    $records[] = "address=/.{$n->tld}/{$n->wireguard_ip}";
                }

                return $records;
            });
        // A Router selection names the Router Node that answers: a selection being published or
        // restored, else a stored Router replacement that has published DNS.
        $selectedRouters = array_values(array_replace(
            $this->routerTransitions->dnsRouters(),
            $this->routerOverrides($clusterOverrides),
        ));
        $records = $nodes
            ->toBase()
            ->merge($this->sites
                ->all()
                ->groupBy('domain')
                ->map(fn ($sites): string => $this->hostRecord(
                    array_values($sites->all()),
                    $selectedRouters,
                )));
        // `node:role:add gateway gateway --converge` marks the singleton assignment provisioning while
        // it republishes DNS, so a converging holder keeps gateway.orbit; an active holder wins.
        $gateway = null;
        foreach ([LifecycleStatus::Active, LifecycleStatus::Provisioning] as $gatewayStatus) {
            $gateway ??= Node::query()
                ->where('status', LifecycleStatus::Active->value)
                ->whereNotNull('wireguard_ip')
                ->whereHas('roles', static fn (Builder $q): Builder => $q->where('role', RoleName::Gateway->value)->where(
                    'status',
                    $gatewayStatus->value,
                ))
                ->first();
        }
        if ($gateway instanceof Node) {
            $records->push('host-record='.ReservedPrivateHostname::Gateway.",{$gateway->wireguard_ip}");

            $metrics = $this->roleNode(RoleName::Metrics, $pendingNode);
            if ($metrics instanceof Node) {
                $records->push('host-record='.ReservedPrivateHostname::Metrics.",{$gateway->wireguard_ip}");
            }
        }

        $websocket = $this->websocket->node($this->roleNode(RoleName::WebSocket, $pendingNode));

        if ($websocket instanceof Node) {
            $records->push('host-record='.WebSocketHostname::Value.",{$websocket->wireguard_ip}");
        }

        $analytics = $this->roleNode(RoleName::Analytics, $pendingNode);

        if ($analytics instanceof Node) {
            $records->push('host-record='.AnalyticsHostname::Value.",{$analytics->wireguard_ip}");
        }

        foreach ($this->contributedRecords($nodeOverrides, $clusterOverrides) as $hostname => $address) {
            $records->push("host-record={$hostname},{$address}");
        }

        foreach ($this->selection->clusterTldRecords($nodeOverrides, $clusterOverrides) as $record) {
            $records->push($record);
        }

        return '# Managed by Orbit.'.PHP_EOL.$records->unique()->sort()->implode(PHP_EOL).PHP_EOL;
    }

    /**
     * The records that extensions contribute, checked before any reaches the dnsmasq configuration. Each hostname
     * must be one that an extension reserves, which makes it a lowercase DNS name under `.orbit`, and must not be a
     * Cluster TLD this publication answers for, or a name under one. Each address must be a host address in the VPN
     * subnet. Only one contribution may publish a hostname. Anything else is a wiring error: it throws, so
     * publication fails closed and no contribution can add a dnsmasq directive.
     *
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState, tld?: ?string, router_node_id?: ?int}>  $clusterOverrides
     * @return array<string, string>
     *
     * @throws LogicException
     */
    private function contributedRecords(array $nodeOverrides, array $clusterOverrides): array
    {
        $reserved = ReservedPrivateHostname::contributed();
        $clusterTlds = null;
        $subnet = null;
        $records = [];

        foreach (app(Contributions::class)->of(ContributedPrivateDnsRecords::class) as $contribution) {
            foreach ($contribution->records() as $hostname => $address) {
                $clusterTlds ??= $this->selection->clusterTlds($nodeOverrides, $clusterOverrides);
                $subnet ??= Ipv4Subnet::from(app(VpnSettings::class)->subnet());
                $problem = match (true) {
                    ! in_array($hostname, $reserved, true) => "the private DNS record [{$hostname}], which no extension reserves",
                    array_key_exists($hostname, $records) => "the private DNS record [{$hostname}], which another contribution publishes",
                    array_any($clusterTlds, static fn (string $tld): bool => $hostname === $tld || str_ends_with($hostname, ".{$tld}")) => "the private DNS record [{$hostname}], which is a Cluster TLD or a name under one",
                    filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false => "the address [{$address}] for [{$hostname}], which is not an IPv4 address",
                    ! $subnet->containsUsableAddress($address) => "the address [{$address}] for [{$hostname}], which is not a host address in the VPN subnet [{$subnet->value()}]",
                    default => null,
                };

                if ($problem !== null) {
                    throw new LogicException('The contribution ['.$contribution::class."] publishes {$problem}.");
                }

                $records[$hostname] = $address;
            }
        }

        return $records;
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
                    $q->where('status', LifecycleStatus::Active->value);

                    if ($pendingNode instanceof Node && $pendingNode->exists) {
                        $q->orWhere('id', $pendingNode->id);
                    }
                })
                ->whereNotNull('wireguard_ip')
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
     */
    private function hostRecord(array $sites, array $selectedRouters): string
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
            return '';
        }

        return "host-record={$site->domain},{$site->nodeAddress}";
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
            ->where('status', LifecycleStatus::Active->value)
            ->whereNotNull('wireguard_ip')
            ->orderBy('id')
            ->get()
            ->each(static function (Node $node) use (&$requesters): void {
                $address = DnsAddress::normalize((string) $node->wireguard_ip);
                if ($address === null) {
                    return;
                }

                $requesters[$address] = $node->id;
            });

        return $requesters;
    }
}
