<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Models\Route;
use App\Support\ValidatedData;

/**
 * The operation-route-removal inputs (flow-tighten rule 22), split by the chart's steps from route:create's lists.
 * Each Node gets its whole Caddyfile without the Route, then its pools, LAN rules and the checks that nothing is left.
 * With --offline, a Node that does not answer is skipped by every later step and keeps residue.
 */
final readonly class NativeRouteRemovalSteps
{
    /** What an unreachable Node still needs; node:converge finishes it. */
    public const array ResidueSteps = ['caddy', 'php', 'certificates', 'firewall'];

    /** @param array<string,mixed> $lifecycle  The removal capture in route:create's lifecycle shape.
     * @return array<string,mixed>
     */
    public function build(Route $route, array $lifecycle, bool $offline): array
    {
        // A removal stages nothing, so it has no preparation.
        $plan = app(NativeRouteCreatePlan::class)->build($route, [...$lifecycle, 'public_activated' => false, 'preparation' => ['certificates' => [], 'lan' => []]]);
        $rows = static fn (string $list): array => RouteExecutionProof::objects($plan[$list]);
        $common = array_intersect_key($plan, array_flip(['leaves', 'leaf_indexes', 'root_certificate', 'nodes']));
        $empty = array_fill_keys(NativeRouteTargetPlan::SiteLists, []) + ['trust' => [], 'lan' => []];
        $mutations = ValidatedData::object(ValidatedData::object($lifecycle['route_mutations'])['forward']);

        // The withdraw step writes each Node's whole Caddyfile and the APP_URL of the Routes that stay.
        $withdraw = NativeRouteTargetPlan::phase([...$empty, 'caddy' => $rows('caddy'), 'url_lines' => $rows('url_lines'), 'env_files' => $rows('env_files')], $common, []);
        $pools = NativeRouteTargetPlan::phase([...$empty, 'php_files' => $rows('php_files'), 'php_tests' => $rows('php_tests'), 'php_reloads' => $rows('php_reloads')], $common, []);
        $cleanup = [];
        foreach (RouteExecutionProof::objects($mutations['cleanup']) as $leaf) {
            $cleanup[] = ['node_id' => ValidatedData::integer($leaf['node_id']), 'leaf' => array_intersect_key($leaf, array_flip(['user', 'scope']))];
        }
        $lan = $ingress = [];
        foreach (RouteExecutionProof::objects($mutations['retire_lan']) as $row) {
            foreach (RouteExecutionProof::objects($row['rules']) as $rule) {
                if ($rule['ensure'] === 'absent') {
                    $lan[] = ['node_id' => ValidatedData::integer($row['node_id']), 'port' => ValidatedData::integer($rule['port']),
                        'protocol' => ValidatedData::string($rule['protocol']), 'source' => ValidatedData::string($rule['source'])];
                }
            }
        }
        foreach (RouteExecutionProof::objects($mutations['retire_ingress']) as $row) {
            foreach (RouteExecutionProof::objects($row['rules']) as $rule) {
                if ($rule['ensure'] === 'absent') {
                    $ingress[] = ['node_id' => ValidatedData::integer($row['node_id']), 'port' => ValidatedData::integer($rule['port'])];
                }
            }
        }
        $nodeIds = static fn (array ...$lists): array => array_values(array_unique(array_map(static fn (array $row): int => ValidatedData::integer($row['node_id']), array_merge(...$lists))));
        $sorted = static function (array $ids): array {
            sort($ids);

            return $ids;
        };
        $withdrawNodes = $sorted([...ValidatedData::integerList($withdraw['site_nodes']), ...$nodeIds($cleanup)]);
        $firewallNodes = $sorted($nodeIds($lan, $ingress));
        $checkNodes = $sorted([...$withdrawNodes, ...$firewallNodes]);
        $dns = $rows('dns');
        $vpn = array_map(static fn (array $row): int => ValidatedData::integer($row['node_id']), $dns);
        // A Node is tried first only with --offline. A VPN Node is never skipped: its records must leave.
        $answer = $offline ? array_values(array_diff(array_values(array_unique([...$checkNodes, ...ValidatedData::integerList($pools['site_nodes'])])), $vpn)) : [];
        sort($answer);

        return ['offline' => $offline, 'nodes' => $plan['nodes'], 'answer_nodes' => $answer, 'answer_index' => $answer === [] ? (object) [] : array_flip(array_map(strval(...), $answer)),
            'dns' => $dns,
            'retire' => $withdraw, 'cleanup' => $cleanup, 'lan_absent' => [], 'retire_nodes' => $withdrawNodes,
            'pools' => $pools, 'lan_remove' => $lan, 'ingress_close' => $ingress, 'firewall_nodes' => $firewallNodes, 'check_nodes' => $checkNodes];
    }
}
