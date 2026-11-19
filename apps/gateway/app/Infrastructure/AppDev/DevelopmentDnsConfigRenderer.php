<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\Analytics\AnalyticsHostname;
use App\Domain\AppDev\ClusterRouterDnsSelection;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\ClusterRouterTransition;
use App\Domain\Routes\ContributedPrivateHostnames;
use App\Domain\Routes\ReservedPrivateHostname;
use App\Domain\Routes\RouteRenderContext;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WebSocket\WebSocketHostname;
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
            // An app-dev Node answers its TLD. So does an app-prod Node outside every Cluster: no Router answers its TLD, and a
            // production clone's preview Route under it must need no record (docs/reference/instance-cloning.md).
            ->whereHas('roles', static fn (Builder $q): Builder => $q->whereIn(
                'role',
                [RoleName::AppDev->value, RoleName::AppProd->value],
            )->whereIn('status', [LifecycleStatus::Provisioning->value, LifecycleStatus::Active->value]))
            ->with('roles')
            ->get();
        $clusterTlds = $this->selection->clusterTlds($nodeOverrides, $clusterOverrides);
        $nodes = $nodes
            ->flatMap(static function (Node $n) use ($clusterTlds, $nodeOverrides): array {
                $identity = app(RouteRenderContext::class);
                $appDev = $n->roles->contains(static fn ($role): bool => $role->role === RoleName::AppDev
                    && in_array($role->status, [LifecycleStatus::Provisioning, LifecycleStatus::Active], true));
                $clusterId = array_key_exists('cluster_id', $nodeOverrides[$n->id] ?? []) ? $nodeOverrides[$n->id]['cluster_id'] : $identity->clusterId($n);
                if (! $appDev && $clusterId !== null) {
                    return [];
                }
                $tld = $identity->tld($n);
                $address = $identity->wireguardIp($n);
                if ($tld === null || $address === null) {
                    return [];
                }
                $records = ["local=/{$tld}/"];

                if (! in_array(rtrim(strtolower($tld), '.'), $clusterTlds, true)) {
                    $records[] = "address=/.{$tld}/{$address}";
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
            $records->push('host-record='.ReservedPrivateHostname::Gateway.','.app(RouteRenderContext::class)->wireguardIp($gateway));

            $metrics = $this->roleNode(RoleName::Metrics, $pendingNode);
            if ($metrics instanceof Node) {
                $records->push('host-record='.ReservedPrivateHostname::Metrics.','.app(RouteRenderContext::class)->wireguardIp($gateway));
            }
        }

        $websocket = $this->websocket->node($this->roleNode(RoleName::WebSocket, $pendingNode));

        if ($websocket instanceof Node) {
            $records->push('host-record='.WebSocketHostname::Value.','.app(RouteRenderContext::class)->wireguardIp($websocket));
        }

        $analytics = $this->roleNode(RoleName::Analytics, $pendingNode);

        if ($analytics instanceof Node) {
            $records->push('host-record='.AnalyticsHostname::Value.','.app(RouteRenderContext::class)->wireguardIp($analytics));
        }

        foreach ($this->hostnames as $contribution) {
            foreach ($contribution->hostnames() as $hostname => $address) {
                if ($address !== null) {
                    $records->push("host-record={$hostname},{$address}");
                }
            }
        }

        foreach ($this->selection->clusterTldRecords($nodeOverrides, $clusterOverrides) as $record) {
            $records->push($record);
        }

        $lines = array_values($records->unique()->sort()->all());
        $answers = $this->selection->answers($nodeOverrides, $clusterOverrides);

        return '# Managed by Orbit.'.PHP_EOL.implode(PHP_EOL, self::withoutAnsweredRecords($lines, $answers)).PHP_EOL;
    }

    /**
     * Private DNS answers by TLD (ADR 0234, docs/reference/private-dns.md#records-a-tld-already-answers): an exact record is left out
     * when every requester gets the same address without it. That holds when the listener's first matching suffix and dnsmasq's most
     * specific `address=` rule both give the record's address, and each requester with overrides either names the domain itself or
     * gets the same address from its suffix override. A record for a site on another Node, outside every TLD, or of a Router that
     * a transition selects keeps its line.
     *
     * @param  list<string>  $lines  The sorted, unique lines of the file.
     * @param  array{suffixes: array<string, string>, overrides: array<string, array<string, string>>}  $answers
     * @return list<string>
     */
    private static function withoutAnsweredRecords(array $lines, array $answers): array
    {
        // dnsmasq matches `address=/name/` and `address=/.name/` alike; the listener's catalog reads only the dotted form
        // (PrivateDnsAnswerCatalog::fromDnsmasqConfiguration), then the selection's suffixes.
        [$addressRules, $listed] = [[], []];
        foreach ($lines as $line) {
            if (preg_match('/^address=\/(\.?)([^\/]+)\/(.+)$/', $line, $matches) === 1) {
                $addressRules[self::normalizeName($matches[2])] = $matches[3];
                if ($matches[1] === '.') {
                    $listed[self::normalizeName($matches[2])] = $matches[3];
                }
            }
        }
        $suffixes = [...$listed, ...$answers['suffixes']];

        return array_values(array_filter($lines, static function (string $line) use ($addressRules, $suffixes, $answers): bool {
            if (preg_match('/^host-record=([^,]+),([^,#\s]+)$/', $line, $matches) !== 1) {
                return true;
            }
            [$name, $address] = [self::normalizeName($matches[1]), $matches[2]];
            $suffix = self::firstSuffix($name, $suffixes);
            if ($suffix === null || $suffixes[$suffix] !== $address || self::mostSpecific($name, $addressRules) !== $address) {
                return true;
            }
            foreach ($answers['overrides'] as $overrides) {
                if (! isset($overrides[$name]) && ($overrides[$suffix] ?? $suffixes[$suffix]) !== $address) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * The LAN override of a name is left out when the requester gets the same address from its suffix override and no exact record
     * stands in between, so a Route of the Cluster under its TLD changes no override (ADR 0234).
     *
     * @param  array<string, string>  $exact
     * @param  array<string, string>  $suffixes
     * @param  array<string, array<string, string>>  $overrides
     * @return array<string, array<string, string>>
     */
    private static function withoutAnsweredOverrides(array $exact, array $suffixes, array $overrides): array
    {
        foreach ($overrides as $key => $names) {
            foreach ($names as $name => $address) {
                if (array_key_exists($name, $suffixes) || isset($exact[$name])) {
                    continue;
                }
                $suffix = self::firstSuffix($name, $suffixes);
                if ($suffix !== null && ($names[$suffix] ?? $suffixes[$suffix]) === $address) {
                    unset($overrides[$key][$name]);
                }
            }
        }

        return $overrides;
    }

    /**
     * The suffix the listener answers a name with: the first that matches, as PrivateDnsAnswerCatalog::addressFor walks them.
     *
     * @param  array<array-key, string>  $suffixes
     */
    private static function firstSuffix(string $name, array $suffixes): ?string
    {
        foreach (array_keys($suffixes) as $key) {
            // PHP stores a numeric-string key as an integer.
            $suffix = (string) $key;
            if ($name === $suffix || str_ends_with($name, '.'.$suffix)) {
                return $suffix;
            }
        }

        return null;
    }

    /**
     * The address dnsmasq answers a name with from its `address=` rules: the most specific rule that matches.
     *
     * @param  array<array-key, string>  $rules
     */
    private static function mostSpecific(string $name, array $rules): ?string
    {
        [$best, $address] = [null, null];
        foreach ($rules as $key => $value) {
            // PHP stores a numeric-string key as an integer.
            $suffix = (string) $key;
            if (($name === $suffix || str_ends_with($name, '.'.$suffix)) && ($best === null || strlen($suffix) > strlen($best))) {
                [$best, $address] = [$suffix, $value];
            }
        }

        return $address;
    }

    private static function normalizeName(string $name): string
    {
        return rtrim(strtolower($name), '.');
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

        $suffixes = [...$catalog->suffixes, ...$answers['suffixes']];

        return new PrivateDnsAnswerCatalog(
            exact: $catalog->exact,
            suffixes: $suffixes,
            overrides: self::withoutAnsweredOverrides($catalog->exact, $suffixes, $overrides),
        );
    }

    /**
     * Earlier releases' requester answers that the catalog no longer gives, as `requester => name => address`.
     * They never enter the published catalog; publishers only withdraw them from the live one.
     *
     * @param  array<int, array{cluster_id?: ?int, lan_ip?: ?string, status?: LifecycleStatus, wireguard_ip?: ?string, wireguard_public_key?: ?string}>  $nodeOverrides
     * @param  array<int, array{state?: ClusterState, tld?: ?string, router_node_id?: ?int}>  $clusterOverrides
     * @return array<string, array<string, string>>
     */
    public function retired(PrivateDnsAnswerCatalog $catalog, array $nodeOverrides = [], array $clusterOverrides = []): array
    {
        $retired = [];
        foreach ($this->selection->retiredAnswers($nodeOverrides, $clusterOverrides) as $key => $names) {
            foreach ($names as $name => $address) {
                if (! isset($catalog->overrides[$key][$name])) {
                    $retired[$key][$name] = $address;
                }
            }
        }

        return $retired;
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
