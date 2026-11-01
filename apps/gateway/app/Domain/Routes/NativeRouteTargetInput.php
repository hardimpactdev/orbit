<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Route;
use App\Models\RouteTarget;
use App\Support\ValidatedData;
use Closure;
use Illuminate\Database\Eloquent\Collection;

/** Freeze target publications while stored associations still describe the actual prior state.
 * @phpstan-import-type CapturedRoute from NativeRouteMutationInput
 */
final readonly class NativeRouteTargetInput
{
    public function __construct(private NativeRouteTargetIntent $targets, private NativeRouteMutationInput $publications,
        private DevelopmentSiteRepository $sites) {}

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function capture(Route $route, array $input): array
    {
        if (($input['mode'] ?? null) === 'targets_recover') {
            throw new ResourceOperationException('route.target_set_recovery_required', 'The committed target transition must finish through its owning Operation.', 409);
        }
        $inventory = $this->targets->forRoute($route, $input);
        $metadata = array_intersect_key($inventory, array_flip(['route_id', 'mode', 'binding_only', 'noop', 'proposal', 'original_associations', 'original_route_states']));
        $metadata += ['effectful' => ! $inventory['binding_only'] && ! $inventory['noop'], 'result_route_id' => $route->id, 'workloads' => []];
        if (! $metadata['effectful']) {
            return ['intent' => $metadata, 'participant_node_ids' => [209], 'certificates' => [], 'execution_secrets' => []];
        }
        $beforeSites = $this->sites->all();
        $owners = RouteExecutionProof::objects($inventory['original_associations']);
        $proposal = NativeRouteTargetIntent::proposal(ValidatedData::object($inventory['proposal']));
        $selectedOwners = [];
        foreach ($owners as $owner) {
            $selectedOwners[ValidatedData::integer($owner['instance_id'])] = ValidatedData::integer($owner['route_id']);
        }
        foreach ($proposal->targetIds as $id) {
            $selectedOwners[$id] = $route->id;
        }
        foreach ($proposal->dispositions as $disposition) {
            if ($disposition->routeId === null) {
                throw new ResourceOperationException('route.target_conflict', 'Every retained disposition requires its admitted destination Route.', 409);
            }
            $selectedOwners[$disposition->instanceId] = $disposition->routeId;
        }
        $routeIds = array_values(array_unique([$route->id, ...ValidatedData::integerList(array_column($owners, 'route_id')), ...array_values($selectedOwners)]));
        $previous = Route::query()->with(['targets.instance.node', 'targets.instance.project', 'cluster.routerAssignment.node'])
            ->whereKey($routeIds)->orderBy('id')->get()->keyBy('id');
        $instances = Instance::query()->with(['node', 'project'])->whereKey(array_keys($selectedOwners))->get()->keyBy('id');
        $candidates = [];
        foreach ($previous as $id => $stored) {
            $candidate = clone $stored;
            $members = [];
            foreach ($stored->targets as $target) {
                if (! isset($selectedOwners[$target->instance_id]) || $selectedOwners[$target->instance_id] === $id) {
                    $members[$target->instance_id] = $target;
                }
            }
            foreach ($selectedOwners as $instanceId => $ownerId) {
                if ($ownerId === $id) {
                    $member = new RouteTarget(['route_id' => $id, 'instance_id' => $instanceId, 'position' => count($members)]);
                    $member->setRelation('instance', $instances->findOrFail($instanceId));
                    $members[$instanceId] = $member;
                }
            }
            if ($id === $route->id) {
                $members = array_map(static fn (int $instanceId): RouteTarget => $members[$instanceId], $proposal->targetIds);
            }
            $candidate->setRelation('targets', new Collection(array_values($members)));
            $candidate->forceFill(['status' => RouteStatus::Active, 'sites_published' => true]);
            $candidates[$id] = $candidate;
        }
        $prior = $desired = $staged = [];
        $stagingScopes = [];
        foreach (RouteExecutionProof::objects($inventory['prepared']) as $member) {
            $stagingScopes[ValidatedData::integer($member['node_id']).':app-instance-'.ValidatedData::integer($member['instance_id'])] = ValidatedData::string($member['certificate_scope']);
        }
        foreach ($previous as $id => $stored) {
            $prior[] = $this->publications->route($stored);
            $selected = $this->publications->route($candidates[$id], $candidates);
            $desired[] = $selected;
            $staged[] = $this->staged($selected, $stagingScopes);
        }
        $originalOwners = array_column($owners, 'route_id', 'instance_id');
        foreach (RouteExecutionProof::objects($inventory['prepared']) as $member) {
            $instanceId = ValidatedData::integer($member['instance_id']);
            if (isset($originalOwners[$instanceId])) {
                continue;
            }
            foreach (app(NativeRouteConvergenceEnvironmentMaterial::class)->forRoute($route, ValidatedData::integer($member['node_id']), ['workloads' => [$member]], true) as $write) {
                foreach ($prior as &$row) {
                    if ($row['route_id'] === $route->id) {
                        $row['url_writes'][] = ['node_id' => ValidatedData::integer($member['node_id']), ...$write];
                    }
                }
                unset($row);
            }
        }
        $capture = $this->publications->capture($prior, $desired, $staged, $prior, $beforeSites, $beforeSites, [],
            static fn (Closure $render): array => $render());
        $workloads = [];
        foreach (RouteExecutionProof::objects($inventory['prepared']) as $member) {
            $instance = $instances->findOrFail(ValidatedData::integer($member['instance_id']));
            $beforeId = array_column($owners, 'route_id', 'instance_id')[$instance->id] ?? null;
            $workloads[] = app(NativeRouteWorkloadPreparation::class)->capture($instance,
                is_int($beforeId) ? $previous->findOrFail($beforeId) : null, $candidates[ValidatedData::integer($member['route_id'])]);
        }

        $forward = ValidatedData::object($capture['route_mutations']['forward']);
        $preparedIds = ValidatedData::integerList(array_column(RouteExecutionProof::objects($inventory['prepared']), 'instance_id'));
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
            if (! in_array($nodeId, ValidatedData::integerList(array_column(RouteExecutionProof::objects($inventory['prepared']), 'node_id')), true)) {
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
                if ($member['node_id'] === $routerId || ! in_array($instanceId, $preparedIds, true)) {
                    continue;
                }
                if ($router === null) {
                    throw new \LogicException('Every prospective Router must have its captured connection.');
                }
                $preparation['probes'][] = [...array_intersect_key($router, array_flip(['node_id', 'control_node_id', 'connection', 'host_keys'])),
                    'probe' => ['address' => app(RouteRenderContext::class)->wireguardIp($instances->findOrFail($instanceId)->node), 'port' => 443, 'domain' => $row['domain']]];
            }
        }
        $forward['prepare_certificates'] = $forward['lan'] = [];
        $capture['route_mutations']['forward'] = $forward;

        return ['intent' => [...$metadata, 'workloads' => $workloads, 'preparation' => $preparation, 'route_mutations' => $capture['route_mutations']],
            'participant_node_ids' => $capture['participant_node_ids'], 'certificates' => $capture['certificates'],
            'execution_secrets' => $capture['execution_secrets']];
    }

    /** Use the target supplier's existing staging scope identities with the canonical site renderer.
     * @param  CapturedRoute  $row
     * @param  array<string,string>  $scopes
     * @return CapturedRoute
     */
    private function staged(array $row, array $scopes): array
    {
        $leaves = [];
        foreach (RouteExecutionProof::objects($row['intent']['certificates']) as $leaf) {
            $scope = $scopes[ValidatedData::integer($leaf['node_id']).':'.ValidatedData::string($leaf['scope'])] ?? null;
            $leaves[] = $scope === null ? $leaf : app(NativeRouteCertificateInput::class)->convergenceInput(
                Node::query()->findOrFail(ValidatedData::integer($leaf['node_id'])), $scope, ValidatedData::string($leaf['domain']));
        }
        $row['intent']['certificates'] = $leaves;
        $row['sites'] = array_map(static function (DevelopmentSite $site) use ($scopes): DevelopmentSite {
            $scope = $scopes[$site->nodeId.':'.($site->certificateScope ?? $site->scope)] ?? null;

            return $scope === null ? $site : $site->withCertificateScope($scope);
        }, $row['sites']);

        return $row;
    }
}
