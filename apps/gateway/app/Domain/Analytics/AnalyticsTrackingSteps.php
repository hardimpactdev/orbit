<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Routes\NativeCaddyPublicationInput;
use App\Domain\Routes\NativeRouteCreatePlan;
use App\Domain\Routes\NativeRouteTargetPlan;
use App\Domain\Routes\RouteRenderContext;
use App\Infrastructure\AppDev\PrivateDnsPublication;
use App\Models\Node;
use App\Support\ValidatedData;

/**
 * The analytics.enable and analytics.disable step inputs (flow-tighten rule 22): each Node's whole lasting Caddyfile,
 * the tracking leaves, the old scopes to remove and the private DNS records, as route:create's children read them.
 */
final readonly class AnalyticsTrackingSteps
{
    /**
     * @param  list<array{node_id:int,content:string}>  $caddy  Each Node's whole lasting Caddyfile.
     * @param  list<array{node_id:int,before:mixed,after:mixed}>  $dns  Each VPN Node's records before and after.
     * @param  list<array{node_id:int,leaf:array<string,mixed>}>  $cleanup  The scopes no tracking site names any more.
     * @param  list<array<string,mixed>>  $leaves  The admitted tracking leaves.
     * @param  list<array{node_id:int,address:string,domain:string}>  $probes
     * @return array<string,mixed>
     */
    public function build(array $caddy, array $dns, array $cleanup, array $leaves, array $probes, bool $enable): array
    {
        $rows = array_map(NativeRouteCreatePlan::leaf(...), $leaves);
        $records = [];
        foreach ($dns as $row) {
            if (is_array($row['after']) && ! PrivateDnsPublication::same($row['after'], is_array($row['before']) ? $row['before'] : null)) {
                $records[] = ['node_id' => $row['node_id'], 'dns' => ValidatedData::object($row['after'])];
            }
        }
        $ids = array_values(array_unique([...array_column($caddy, 'node_id'), ...array_column($cleanup, 'node_id'), ...array_column($records, 'node_id'), ...array_column($rows, 'node_id')]));
        $nodes = [];
        foreach (Node::query()->whereKey($ids)->orderBy('id')->get() as $node) {
            $connection = app(NativeCaddyPublicationInput::class)->connectionForNode($node);
            $nodes[(string) $node->id] = ['host' => $connection['connection']['nodeHost'], 'user' => $connection['connection']['nodeUser'],
                'port' => $connection['connection']['nodePort'], 'identity_file' => $connection['connection']['nodeIdentityFile'], 'host_keys' => $connection['host_keys'],
                'account' => app(RouteRenderContext::class)->user($node)];
        }
        $common = ['leaves' => $rows, 'leaf_indexes' => array_keys($rows), 'root_certificate' => $rows === [] ? '' : app(LeafCertificateSigner::class)->rootCertificate(),
            'nodes' => $nodes === [] ? (object) [] : $nodes];
        $site = NativeRouteTargetPlan::phase([...array_fill_keys(NativeRouteTargetPlan::SiteLists, []), 'lan' => [], 'caddy' => $caddy,
            'trust' => array_values(array_unique(array_column($rows, 'node_id')))], $common, array_keys($rows));
        $siteNodes = array_values(array_unique([...ValidatedData::integerList($site['site_nodes']), ...array_column($cleanup, 'node_id')]));
        sort($siteNodes);

        // The withdraw or publish step is route-target-retire: each Node's whole Caddyfile, then the old scopes.
        return [...$common, 'retire' => $site, 'cleanup' => $cleanup, 'lan_absent' => [], 'retire_nodes' => $siteNodes, 'dns' => $records,
            ...($enable ? ['probes' => $probes] : ['check_nodes' => $siteNodes, 'lan_remove' => [], 'ingress_close' => []])];
    }
}
