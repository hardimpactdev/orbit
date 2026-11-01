<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Infrastructure\AppDev\DevelopmentSite;
use App\Models\Instance;
use App\Models\Node;
use App\Support\ValidatedData;
use Illuminate\Support\Collection;

/** @phpstan-import-type CapturedRoute from NativeRouteMutationInput
 * Canonical workload-only staging before Routers may select newly prepared TLS endpoints. */
final readonly class NativeRoutePreparationInput
{
    /** @param array{route_mutations:array<string,mixed>,certificates:list<array<string,mixed>>,participant_node_ids:list<int>,execution_secrets:array<string,array{vault:string,key:string}>} $capture
     * @param  list<CapturedRoute>  $staged
     * @param  list<CapturedRoute>  $desired
     * @param  Collection<int,DevelopmentSite>  $beforeSites
     * @param  array<int,Instance>  $instances
     * @return array{capture:array{route_mutations:array<string,mixed>,certificates:list<array<string,mixed>>,participant_node_ids:list<int>,execution_secrets:array<string,array{vault:string,key:string}>},preparation:array<string,mixed>}
     */
    public function capture(array $capture, array $staged, array $desired, Collection $beforeSites, array $instances): array
    {
        $forward = ValidatedData::object(ValidatedData::object($capture['route_mutations'])['forward']);
        $preparedIds = array_keys($instances);
        $workloadScopes = array_map(static fn (int $id): string => 'app-instance-'.$id, $preparedIds);
        $servingHops = $beforeSites->filter(static fn (DevelopmentSite $site): bool => $site->isProxy() || $site->publicListener)
            ->map(static fn (DevelopmentSite $site): string => $site->addressKey())->all();
        $workloadSites = collect(array_merge(...array_column($staged, 'sites')))
            ->filter(static fn (DevelopmentSite $site): bool => in_array($site->scope, $workloadScopes, true) && ! in_array($site->addressKey(), $servingHops, true))->values();
        $addresses = $workloadSites->map(static fn (DevelopmentSite $site): string => $site->addressKey())->all();
        $workloadCatalog = $beforeSites->reject(static fn (DevelopmentSite $site): bool => in_array($site->addressKey(), $addresses, true))->values()->merge($workloadSites);
        $preparation = ['certificates' => RouteExecutionProof::objects($forward['prepare_certificates']), 'lan' => RouteExecutionProof::objects($forward['lan']), 'publications' => [], 'probes' => []];
        $publications = RouteExecutionProof::objects($forward['publications']);
        foreach ($publications as $publication) {
            $nodeId = ValidatedData::integer($publication['node_id']);
            if (! in_array($nodeId, array_map(static fn (Instance $instance): int => $instance->node_id, $instances), true)) {
                continue;
            }
            $prepared = app(NativeRoutePublicationInput::class)->snapshot(Node::query()->findOrFail($nodeId), $workloadCatalog);
            $preparation['publications'][] = [...array_intersect_key($publication, array_flip(['node_id', 'user', 'control_node_id', 'connection', 'host_keys'])),
                'before' => ValidatedData::object($publication['before'])['caddy'], 'after' => $prepared['caddy'], 'direction' => 'forward',
                'alternatives' => array_map(static fn (string $stage): mixed => ValidatedData::object($publication[$stage])['caddy'], ['before', 'prepared', 'withdrawn', 'after'])];
        }
        foreach ($desired as $row) {
            $routerId = $row['intent']['router_node_id'];
            if (! is_int($routerId)) {
                continue;
            }
            $router = array_find($publications, static fn (array $publication): bool => $publication['node_id'] === $routerId);
            foreach (RouteExecutionProof::objects($row['intent']['workloads']) as $member) {
                $instanceId = ValidatedData::integer($member['instance_id']);
                if ($member['node_id'] === $routerId || ! in_array($instanceId, $preparedIds, true)
                    || ! $workloadSites->contains(static fn (DevelopmentSite $site): bool => $site->nodeId === $member['node_id'] && $site->domain === $row['domain'])) {
                    continue;
                }
                if ($router === null) {
                    throw new \LogicException('Every prospective Router must have its captured connection.');
                }
                $preparation['probes'][] = [...array_intersect_key($router, array_flip(['node_id', 'control_node_id', 'connection', 'host_keys'])),
                    'probe' => ['address' => app(RouteRenderContext::class)->wireguardIp($instances[$instanceId]->node), 'port' => 443, 'domain' => $row['domain']]];
            }
        }
        $forward['prepare_certificates'] = $forward['lan'] = [];
        $mutations = ValidatedData::object($capture['route_mutations']);
        $mutations['forward'] = $forward;
        $capture['route_mutations'] = $mutations;

        return ['capture' => $capture, 'preparation' => $preparation];
    }
}
