<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Nodes\SwampNode;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Route;
use App\Support\ValidatedData;

/**
 * The operation-route-update inputs (flow-tighten rule 22), split by the chart's steps from route:create's lists.
 * The publish step writes each Node's prepared Caddyfile, which serves the old and the new site side by side;
 * the withdraw step writes the lasting one after the cutover.
 */
final readonly class NativeRouteUpdatePlan
{
    /** @param array<string,mixed> $lifecycle  The capture in route:create's lifecycle shape, for the selected Route.
     * @return array<string,mixed>
     */
    public function build(Route $selected, array $lifecycle): array
    {
        $renders = is_array($lifecycle['renders'] ?? null) ? $lifecycle['renders'] : [];
        $prepared = array_map(static fn (mixed $render): mixed => is_array($render) ? [...$render, 'caddy' => $render['prepared'] ?? null] : $render, $renders);
        $now = app(NativeRouteCreatePlan::class)->build($selected, [...$lifecycle, 'public_activated' => false, 'renders' => $prepared]);
        $after = app(NativeRouteCreatePlan::class)->build($selected, [...$lifecycle, 'public_activated' => false]);
        $rows = static fn (array $plan, string $list): array => RouteExecutionProof::objects($plan[$list]);
        $ingressId = $selected->cluster?->ingressAssignment?->node_id;
        $edge = static fn (array $row): bool => ValidatedData::integer($row['node_id']) === $ingressId;
        $common = array_intersect_key($now, array_flip(['leaves', 'leaf_indexes', 'root_certificate', 'nodes']));
        $empty = array_fill_keys(NativeRouteTargetPlan::SiteLists, []) + ['trust' => [], 'lan' => []];

        $publish = $empty;
        foreach (['acl', 'links', 'php_files', 'php_tests', 'php_reloads'] as $list) {
            $publish[$list] = $rows($now, $list);
        }
        $publish['caddy'] = array_values(array_filter($rows($now, 'caddy'), static fn (array $row): bool => ! $edge($row)));
        $publish['trust'] = ValidatedData::integerList($now['trust']);
        $publish['lan'] = $rows($now, 'lan');
        $url = [...$empty, 'url_lines' => $rows($now, 'url_lines'), 'env_files' => $rows($now, 'env_files')];
        $withdraw = [...$empty, 'caddy' => array_values(array_filter($rows($after, 'caddy'), static fn (array $row): bool => ! $edge($row)))];

        // The edge: the Ingress's whole Caddyfile, and its public ports opened or, for the last public Route, closed.
        $mutations = ValidatedData::object(ValidatedData::object($lifecycle['route_mutations'])['forward']);
        // retire_ingress is each port's final state: present when the edge opens, absent when the last public Route leaves.
        $ports = [];
        foreach (RouteExecutionProof::objects($mutations['retire_ingress']) as $row) {
            foreach (RouteExecutionProof::objects($row['rules']) as $rule) {
                $ports[] = ['node_id' => ValidatedData::integer($row['node_id']), 'port' => ValidatedData::integer($rule['port']), 'ensure' => ValidatedData::string($rule['ensure'])];
            }
        }
        $ingress = array_values(array_filter($rows($after, 'caddy'), $edge));

        $cleanup = $lanAbsent = [];
        foreach (RouteExecutionProof::objects($mutations['cleanup']) as $leaf) {
            $cleanup[] = ['node_id' => ValidatedData::integer($leaf['node_id']), 'leaf' => array_intersect_key($leaf, array_flip(['user', 'scope']))];
        }
        foreach (RouteExecutionProof::objects($mutations['retire_lan']) as $row) {
            foreach (RouteExecutionProof::objects($row['rules']) as $rule) {
                if ($rule['ensure'] === 'absent') {
                    $lanAbsent[] = ['node_id' => ValidatedData::integer($row['node_id']), 'port' => ValidatedData::integer($rule['port']),
                        'protocol' => ValidatedData::string($rule['protocol']), 'source' => ValidatedData::string($rule['source'])];
                }
            }
        }
        $installs = NativeRouteTargetPlan::installs($lifecycle, RouteExecutionProof::objects($now['leaves']));
        $retire = NativeRouteTargetPlan::phase([...$withdraw, 'trust' => $publish['trust']], $common, $installs['late']);
        $retireNodes = array_values(array_unique([...ValidatedData::integerList($retire['site_nodes']), ...array_column($cleanup, 'node_id'), ...array_column($lanAbsent, 'node_id')]));
        sort($retireNodes);
        $edgeNodes = array_values(array_unique([...array_column($ports, 'node_id'), ...array_map(static fn (array $row): int => ValidatedData::integer($row['node_id']), $ingress)]));
        sort($edgeNodes);
        $dns = $rows($now, 'dns');

        // The Ingress serves a public name only once Let's Encrypt issues it, so the probes cover the private serving Nodes.
        $probes = array_values(array_filter($rows($now, 'probes'), static fn (array $row): bool => ! $edge($row)));

        return ['leaves' => $now['leaves'], 'leaf_indexes' => $now['leaf_indexes'], 'nodes' => $now['nodes'], 'probes' => $probes, 'dns' => $dns,
            'probe_ssh' => $probes === [] ? (object) [] : app(SwampSshExecutionInput::class)->forNode(SwampNode::node()),
            'publish' => NativeRouteTargetPlan::phase($publish, $common, $installs['early']), 'app_url' => NativeRouteTargetPlan::phase($url, $common, []),
            'ingress' => $ingress, 'ingress_ports' => $ports, 'edge_nodes' => $edgeNodes,
            // Resolvers that honor the TTL stop asking for the old name before its site leaves.
            'retire' => $retire, 'cleanup' => $cleanup, 'lan_absent' => $lanAbsent, 'retire_nodes' => $retireNodes];
    }
}
