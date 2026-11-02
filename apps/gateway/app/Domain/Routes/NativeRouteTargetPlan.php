<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Models\Node;
use App\Models\Route;
use App\Support\ValidatedData;

/**
 * The operation-route-target-set inputs (flow-tighten rule 22). It takes route:create's lists (NativeRouteCreatePlan)
 * and splits them by the chart's steps: each phase is a whole route-create-site intent for its Nodes.
 * A development target publishes in one step; a production set stages the new targets, switches the Router pool,
 * then retires what no Route needs.
 */
final readonly class NativeRouteTargetPlan
{
    private const array SiteLists = ['acl', 'links', 'php_files', 'php_tests', 'php_reloads', 'caddy', 'url_lines', 'env_files'];

    /**
     * @param  array<string,mixed>  $lifecycle  The capture in route:create's lifecycle shape.
     * @param  list<array{node_id:int,domain:string}>  $joining  Each target that joins a Route, with that Route's domain.
     * @param  array<int|string,mixed>  $staged  Each new target Node's whole staged Caddyfile.
     * @return array<string,mixed>
     */
    public function build(Route $route, array $lifecycle, bool $development, array $joining, array $staged): array
    {
        $newNodeIds = array_values(array_unique(array_column($joining, 'node_id')));
        $plan = app(NativeRouteCreatePlan::class)->build($route, $lifecycle);
        $rows = static fn (string $list): array => RouteExecutionProof::objects($plan[$list]);
        $routerId = $route->cluster?->routerAssignment?->node_id;
        $common = array_intersect_key($plan, array_flip(['leaves', 'leaf_indexes', 'root_certificate', 'nodes']));
        $empty = array_fill_keys(self::SiteLists, []) + ['trust' => [], 'lan' => [], 'site_nodes' => []];
        $result = ['leaves' => $plan['leaves'], 'leaf_indexes' => $plan['leaf_indexes'], 'nodes' => $plan['nodes'], 'probes' => [], 'dns' => $plan['dns'], 'cleanup' => [], 'lan_absent' => []];
        $nodeOf = static fn (array $row): int => ValidatedData::integer($row['node_id']);
        if ($development) {
            // instance:create already gave Caddy access to the web root, so no access commands run here.
            $publish = ['trust' => ValidatedData::integerList($plan['trust']), 'lan' => [], 'acl' => []];
            foreach (array_diff(self::SiteLists, ['acl']) as $list) {
                $publish[$list] = $rows($list);
            }

            return [...$result, 'publish' => $this->phase($publish, $common, true), 'stage' => $this->phase($empty, $common, false),
                'router' => $this->phase($empty, $common, false), 'retire' => $this->phase($empty, $common, false), 'retire_nodes' => []];
        }

        $mine = static fn (array $rows, array $nodes): array => array_values(array_filter($rows,
            static fn (array $row): bool => in_array(ValidatedData::integer($row['node_id']), $nodes, true)));
        $stage = $empty;
        foreach (self::SiteLists as $list) {
            $stage[$list] = $list === 'caddy' ? [] : $mine($rows($list), $newNodeIds);
        }
        foreach ($newNodeIds as $nodeId) {
            if (is_string($staged[(string) $nodeId] ?? null)) {
                $stage['caddy'][] = ['node_id' => $nodeId, 'content' => $staged[(string) $nodeId]];
            }
        }
        $stage['trust'] = ValidatedData::integerList($plan['trust']);
        $stage['lan'] = $rows('lan');

        $others = array_values(array_diff(array_map($nodeOf, $rows('caddy')), [$routerId]));
        $retire = $empty;
        foreach (self::SiteLists as $list) {
            $retire[$list] = $list === 'caddy' ? $mine($rows('caddy'), $others) : ($list === 'acl' || $list === 'links' ? [] : array_values(array_filter($rows($list),
                static fn (array $row): bool => ! in_array(ValidatedData::integer($row['node_id']), $newNodeIds, true))));
        }
        $router = $empty;
        $router['caddy'] = $mine($rows('caddy'), array_filter([$routerId], is_int(...)));

        // Swamp probes each new target from 209 on 443, with its Route's domain as SNI, before the Router sends it traffic.
        foreach ($joining as $target) {
            $address = $target['node_id'] === $routerId ? null : app(RouteRenderContext::class)->wireguardIp(Node::query()->findOrFail($target['node_id']));
            if (is_string($address)) {
                $result['probes'][] = ['node_id' => $target['node_id'], 'address' => $address, 'domain' => $target['domain']];
            }
        }
        $mutations = ValidatedData::object(ValidatedData::object($lifecycle['route_mutations'])['forward']);
        foreach (RouteExecutionProof::objects($mutations['cleanup']) as $leaf) {
            $result['cleanup'][] = ['node_id' => ValidatedData::integer($leaf['node_id']), 'leaf' => array_intersect_key($leaf, array_flip(['user', 'scope']))];
        }
        foreach (RouteExecutionProof::objects($mutations['retire_lan']) as $row) {
            foreach (RouteExecutionProof::objects($row['rules']) as $rule) {
                if ($rule['ensure'] === 'absent') {
                    $result['lan_absent'][] = ['node_id' => ValidatedData::integer($row['node_id']), 'port' => ValidatedData::integer($rule['port']),
                        'protocol' => ValidatedData::string($rule['protocol']), 'source' => ValidatedData::string($rule['source'])];
                }
            }
        }

        $retire = $this->phase($retire, $common, false);
        $retireNodes = array_values(array_unique([...ValidatedData::integerList($retire['site_nodes']), ...array_column($result['cleanup'], 'node_id'), ...array_column($result['lan_absent'], 'node_id')]));
        sort($retireNodes);

        return [...$result, 'publish' => $this->phase($empty, $common, false), 'stage' => $this->phase($stage, $common, true),
            'router' => $this->phase($router, $common, false), 'retire' => $retire, 'retire_nodes' => $retireNodes];
    }

    /**
     * One whole route-create-site intent. Only the phase that installs the leaves sees them.
     *
     * @param  array<string,list<array<string,mixed>>|list<int>>  $lists
     * @param  array<string,mixed>  $common
     * @return array<string,mixed>
     */
    private function phase(array $lists, array $common, bool $installs): array
    {
        $trust = $installs ? ValidatedData::integerList($lists['trust']) : [];
        $indexes = $installs ? ValidatedData::integerList($common['leaf_indexes']) : [];
        $nodes = $trust;
        foreach (['lan', ...self::SiteLists] as $list) {
            foreach (RouteExecutionProof::objects($lists[$list]) as $row) {
                $nodes[] = ValidatedData::integer($row['node_id']);
            }
        }
        $leaves = RouteExecutionProof::objects($common['leaves']);
        foreach ($indexes as $index) {
            $nodes[] = ValidatedData::integer($leaves[$index]['node_id']);
        }
        $phase = [...$common, ...array_intersect_key($lists, array_flip(['lan', ...self::SiteLists])), 'trust' => $trust, 'leaf_indexes' => $indexes];
        $nodes = array_values(array_unique($nodes));
        sort($nodes);

        return [...$phase, 'site_nodes' => $nodes];
    }
}
