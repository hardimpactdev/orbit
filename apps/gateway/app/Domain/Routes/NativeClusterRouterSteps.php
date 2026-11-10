<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Certificates\LeafCertificateSigner;
use App\Infrastructure\AppDev\PrivateDnsPublication;
use App\Models\Node;
use App\Support\ValidatedData;

/**
 * The operation-cluster-router inputs (flow-tighten rule 22), split by the chart's steps. Each step writes the whole
 * files the Gateway rendered: the prepared Caddyfiles serve every Cluster Route from the candidate beside the old
 * Router, the old Router's lasting Caddyfile drops them, and the restore step writes the before files back.
 */
final readonly class NativeClusterRouterSteps
{
    /** @param array<string,mixed> $input  The Operation input with its cluster_router capture.
     * @return array<string,mixed>
     */
    public function build(array $input, bool $unset): array
    {
        $intent = ValidatedData::object($input['cluster_router']);
        $renders = is_array($input['renders'] ?? null) ? $input['renders'] : [];
        $mutations = ValidatedData::object($input['route_mutations']);
        $forward = ValidatedData::object($mutations['forward']);
        $candidate = is_array($intent['candidate'] ?? null) ? $intent['candidate'] : null;
        $oldIds = array_map(static fn (array $row): int => ValidatedData::integer($row['node_id']), RouteExecutionProof::objects($intent['old_nodes']));
        $content = static fn (int|string $nodeId, string $state): ?string => is_array($renders[(string) $nodeId] ?? null) && is_string($renders[(string) $nodeId][$state] ?? null)
            ? $renders[(string) $nodeId][$state] : null;

        $leaves = array_map(NativeRouteCreatePlan::leaf(...), RouteExecutionProof::objects($forward['prepare_certificates']));
        $installs = NativeRouteTargetPlan::installs(['renders' => $renders], $leaves);
        $publishCaddy = $restoreCaddy = $retireCaddy = $dns = [];
        foreach ($renders as $nodeId => $render) {
            $nodeId = (int) $nodeId;
            [$before, $prepared, $after] = [$content($nodeId, 'before'), $content($nodeId, 'prepared'), $content($nodeId, 'caddy')];
            if (is_string($prepared) && $prepared !== $before) {
                $publishCaddy[] = ['node_id' => $nodeId, 'content' => $prepared];
                if (is_string($before)) {
                    $restoreCaddy[] = ['node_id' => $nodeId, 'content' => $before];
                }
            }
            if (is_string($after) && $after !== $prepared && ($unset || in_array($nodeId, $oldIds, true))) {
                $retireCaddy[] = ['node_id' => $nodeId, 'content' => $after];
            }
            $dnsAfter = is_array($render) && is_array($render['dns'] ?? null) ? ValidatedData::object($render['dns']) : null;
            $dnsBefore = is_array($render) && is_array($render['dns_before'] ?? null) ? ValidatedData::object($render['dns_before']) : null;
            if ($dnsAfter !== null && ! PrivateDnsPublication::same($dnsAfter, $dnsBefore)) {
                $dns[] = ['node_id' => $nodeId, 'dns' => $dnsAfter];

            }
        }
        $rules = static function (string $side, string $ensure) use ($forward): array {
            $rows = [];
            foreach (RouteExecutionProof::objects($forward[$side]) as $row) {
                foreach (RouteExecutionProof::objects($row['rules']) as $rule) {
                    if ($rule['ensure'] === $ensure) {
                        $rows[] = ['node_id' => ValidatedData::integer($row['node_id']), 'port' => ValidatedData::integer($rule['port']),
                            'protocol' => ValidatedData::string($rule['protocol']), 'source' => ValidatedData::string($rule['source'])];
                    }
                }
            }

            return $rows;
        };
        $lan = $rules('lan', 'present');
        $retireLan = $rules('retire_lan', 'absent');
        $cleanup = array_map(static fn (array $leaf): array => ['node_id' => ValidatedData::integer($leaf['node_id']), 'leaf' => array_intersect_key($leaf, array_flip(['user', 'scope']))],
            RouteExecutionProof::objects($forward['cleanup']));

        // From the candidate over the LAN: every Cluster Route's workload answers with a certificate the Orbit root trusts.
        $probes = [];
        if ($candidate !== null) {
            foreach (RouteExecutionProof::objects($forward['routes']) as $route) {
                foreach (Node::query()->whereKey(ValidatedData::integerList($route['node_ids']))->orderBy('id')->get() as $node) {
                    if ($node->id !== ValidatedData::integer($candidate['node_id'])) {
                        $probes[] = ['node_id' => $node->id, 'from' => ValidatedData::integer($candidate['node_id']), 'address' => $node->lan_ip ?? $node->wireguard_ip,
                            'domain' => ValidatedData::string($route['domain'])];
                    }
                }
            }
        }

        $nodes = [];
        foreach (Node::query()->whereKey(array_map(intval(...), array_keys($renders)))->get() as $node) {
            $connection = app(NativeCaddyPublicationInput::class)->connectionForNode($node);
            $nodes[(string) $node->id] = ['control_node_id' => $connection['control_node_id'], 'host' => $connection['connection']['nodeHost'], 'user' => $connection['connection']['nodeUser'],
                'port' => $connection['connection']['nodePort'], 'identity_file' => $connection['connection']['nodeIdentityFile'], 'host_keys' => $connection['host_keys'],
                'account' => app(RouteRenderContext::class)->user($node)];
        }
        ksort($nodes);
        $common = ['retain_dns_selection' => ($candidate['dns_selected'] ?? false) === true, 'leaves' => $leaves, 'leaf_indexes' => array_keys($leaves), 'root_certificate' => $leaves === [] ? '' : app(LeafCertificateSigner::class)->rootCertificate(),
            'nodes' => $nodes === [] ? (object) [] : $nodes];
        $empty = array_fill_keys(NativeRouteTargetPlan::SiteLists, []) + ['lan' => []];
        $trust = array_values(array_unique(array_column($leaves, 'node_id')));
        $retire = NativeRouteTargetPlan::phase([...$empty, 'caddy' => $retireCaddy, 'trust' => $trust], $common, $installs['late']);
        $retireNodes = array_values(array_unique([...ValidatedData::integerList($retire['site_nodes']), ...array_column($cleanup, 'node_id'), ...array_column($retireLan, 'node_id')]));
        sort($retireNodes);
        $restore = NativeRouteTargetPlan::phase([...$empty, 'caddy' => $restoreCaddy], $common, []);

        if ($unset) {
            // Unset withdraws the Router sites, then removes and checks the LAN rules in their own steps.
            $firewallNodes = array_values(array_unique(array_column($retireLan, 'node_id')));
            sort($firewallNodes);
            $checkNodes = array_values(array_unique([...ValidatedData::integerList($retire['site_nodes']), ...$firewallNodes]));
            sort($checkNodes);

            return [...$common, 'dns' => $dns, 'retire' => $retire, 'cleanup' => $cleanup, 'lan_absent' => [], 'retire_nodes' => $retireNodes,
                'lan_remove' => $retireLan, 'ingress_close' => [], 'firewall_nodes' => $firewallNodes, 'check_nodes' => $checkNodes];
        }

        return [...$common, 'candidate' => $candidate === null ? [] : [['node_id' => ValidatedData::integer($candidate['node_id']), 'intent' => $candidate]],
            'lan_present' => $lan, 'publish' => NativeRouteTargetPlan::phase([...$empty, 'caddy' => $publishCaddy, 'trust' => $trust], $common, $installs['early']),
            'probes' => $probes, 'dns' => $dns, 'select' => $dns === [] ? [] : [['records' => count($dns)]],
            'retire' => $retire, 'cleanup' => $cleanup, 'lan_absent' => $retireLan, 'retire_nodes' => $retireNodes,
            'restore' => $restore, 'restore_lan' => $lan];
    }
}
