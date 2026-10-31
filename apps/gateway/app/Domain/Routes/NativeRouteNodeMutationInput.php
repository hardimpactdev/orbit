<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Actions\Routes\RouteMutationState;
use App\Domain\Analytics\AnalyticsRoutePlan;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Node;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Complete Route supplier for the owning Node admission; no Operation or Node mutation.
 * @phpstan-type CapturedRoute array{route_id:int,domain:string,intent:array<string,mixed>,sites:list<DevelopmentSite>,url_writes:list<array{node_id:int,instance_id:int,name:string,input:array<string,mixed>}>,lan:list<array<string,mixed>>}
 */
final readonly class NativeRouteNodeMutationInput
{
    public function __construct(private NativeRouteMutationPlan $mutations, private RouteMutationReconciler $reconciler,
        private NativeRouteConvergenceIntent $convergence, private NativeRoutePublicationInput $publications,
        private DevelopmentSiteRepository $sites, private SwampCommandStep $steps) {}

    /** The parent owns its admission transaction and retains the returned capture unchanged.
     * @return array{route_mutations:array<string,mixed>,participant_node_ids:list<int>,certificates:list<array<string,mixed>>,execution_secrets:array<string,array{vault:string,key:string}>}
     */
    public function capture(Node $actual, NativeRouteNodeRender $candidate, ?int $clusterId): array
    {
        if ($actual->id !== $candidate->nodeId || app(RouteRenderContext::class)->nodeId() !== null) {
            throw new ResourceOperationException('route.node_render_invalid', 'Discover mutations from the actual Node outside a prospective render.', 422);
        }

        return DB::transaction(function () use ($actual, $candidate, $clusterId): array {
            $actual = Node::query()->findOrFail($actual->id);
            $beforeSites = $this->sites->all();
            $changes = $this->reconciler->activePrivatePlacementChanges([
                $actual->id => ['tld' => $candidate->tld, 'cluster_id' => $clusterId],
            ]);
            $routes = Route::query()->with(['targets.instance.node', 'targets.instance.project', 'cluster.routerAssignment.node'])
                ->where('sites_published', true)->where('kind', RouteKind::App)->where('status', '!=', RouteStatus::Retiring)
                ->where(fn ($q) => $q->where('node_id', $actual->id)->orWhere('generation_basis_node_id', $actual->id)
                    ->orWhereHas('targets.instance', fn ($q) => $q->where('node_id', $actual->id))
                    ->orWhereHas('cluster.routerAssignment', fn ($q) => $q->where('node_id', $actual->id)))->orderBy('id')->get();
            $changes = array_values(array_filter($changes, static fn (array $change): bool => $change['route']->isApp()));
            $batch = $this->mutations->capture($changes);
            foreach ($changes as $change) {
                if (! $routes->contains('id', $change['route']->id)) {
                    $routes->push($change['route']);
                }
            }
            $previous = [];
            foreach ($routes as $route) {
                $previous[] = $this->route($route);
            }
            $desired = app(RouteRenderContext::class)->render($candidate, fn (): array => $this->desiredRoutes($routes, $batch, false));
            $staged = app(RouteRenderContext::class)->render($candidate, fn (): array => $this->desiredRoutes($routes, $batch, true));
            $forwardPrior = app(RouteRenderContext::class)->render($candidate, function () use ($routes): array {
                $result = [];
                foreach ($routes as $route) {
                    $result[] = $this->route($route);
                }

                return $result;
            });
            $analyticsChanges = [];
            foreach (RouteExecutionProof::objects($batch['changes']) as $change) {
                $source = Route::query()->findOrFail(ValidatedData::integer($change['route_id']));
                $next = Route::query()->findOrFail(ValidatedData::integer($change['result_route_id']));
                $placement = ValidatedData::object($change['placement']);
                $next = app(RouteMutationState::class)->candidateWithPlacement($next, new RoutePlacement(
                    $placement['node_id'] === null ? null : ValidatedData::integer($placement['node_id']),
                    $placement['cluster_id'] === null ? null : ValidatedData::integer($placement['cluster_id']), null));
                foreach (app(AnalyticsRoutePlan::class)->captureUpdate($source, $next) as $trackingChange) {
                    $analyticsChanges[] = $trackingChange;
                    $tracking = Route::query()->findOrFail($trackingChange['route_id']);
                    $previous[] = $this->route($tracking);
                    $candidateTracking = clone $tracking;
                    $candidateTracking->unsetRelations();
                    $candidateTracking->forceFill($trackingChange['after']);
                    $candidateTracking->replacement_step = RouteReplacementStep::Cleanup;
                    $desired[] = app(RouteRenderContext::class)->render($candidate, fn (): array => $this->route($candidateTracking));
                    $candidateTracking->replacement_step = null;
                    $staged[] = app(RouteRenderContext::class)->render($candidate, fn (): array => $this->route($candidateTracking));
                    $forwardPrior[] = app(RouteRenderContext::class)->render($candidate, fn (): array => $this->route($tracking));
                }
            }
            $domains = array_values(array_unique([...array_column($previous, 'domain'), ...array_column($desired, 'domain')]));
            $candidateSites = app(RouteRenderContext::class)->render($candidate, fn () => $this->sites->all());
            $addressNodes = $candidateSites->filter(static fn (DevelopmentSite $site): bool => $beforeSites->first(static fn (DevelopmentSite $before): bool => $before->addressKey() === $site->addressKey()) != $site)->pluck('nodeId')->all();
            $afterSites = $candidateSites->reject(static fn (DevelopmentSite $site): bool => in_array($site->domain, $domains, true))->values();
            foreach ($desired as $row) {
                $afterSites->push(...$row['sites']);
            }
            $unaffected = $candidateSites->reject(static fn (DevelopmentSite $site): bool => in_array($site->domain, $domains, true))->values();
            $withdrawnSites = clone $unaffected;
            foreach ($staged as $row) {
                $withdrawnSites->push(...$row['sites']);
            }
            $preparedSites = clone $withdrawnSites;
            foreach ($forwardPrior as $row) {
                $preparedSites->push(...$row['sites']);
            }
            $preparedSites = $preparedSites->unique(static fn (DevelopmentSite $site): string => $site->addressKey())->values();
            $nodeIds = array_values(array_unique(ValidatedData::integerList([...ValidatedData::integerList($batch['node_ids']), ...$addressNodes,
                ...$beforeSites->filter(static fn (DevelopmentSite $site): bool => in_array($site->domain, $domains, true))->pluck('nodeId')->all(),
                ...$afterSites->filter(static fn (DevelopmentSite $site): bool => in_array($site->domain, $domains, true))->pluck('nodeId')->all(),
                ...PrivateDnsPublicationNodes::query()->pluck('id')->all()])));
            sort($nodeIds);
            $publications = [];
            $participants = $nodeIds;
            foreach ($nodeIds as $index => $nodeId) {
                $node = Node::query()->findOrFail($nodeId);
                $before = $this->publications->snapshot($node, $beforeSites);
                $forward = app(RouteRenderContext::class)->render($candidate, fn (): array => [
                    ...app(NativeCaddyPublicationInput::class)->connectionForNode($node),
                    'prepared' => $this->publications->snapshot($node, $preparedSites),
                    'withdrawn' => $this->publications->snapshot($node, $withdrawnSites),
                    'after' => $this->publications->snapshot($node, $afterSites),
                ]);
                $participants[] = ValidatedData::integer($forward['control_node_id']);
                $serves = in_array($nodeId, $addressNodes, true) || $beforeSites->where('nodeId', $nodeId)->contains(static fn (DevelopmentSite $site): bool => in_array($site->domain, $domains, true))
                    || $afterSites->where('nodeId', $nodeId)->contains(static fn (DevelopmentSite $site): bool => in_array($site->domain, $domains, true));
                $publications[] = ['index' => $index, 'node_id' => $nodeId, 'user' => $node->user, 'serves_routes' => $serves,
                    ...$forward, 'before' => $before, 'restore_connection' => app(NativeCaddyPublicationInput::class)->connectionForNode($node)['connection']];
            }
            $captureId = bin2hex(random_bytes(8));
            $secrets = [];
            $forward = $this->stage($desired, $publications, 'forward', $captureId, $secrets);
            $restore = $this->stage($previous, $publications, 'restore', $captureId, $secrets);
            $forward['prepare_certificates'] = array_values(array_unique(array_merge(...array_map(static fn (array $row): array => RouteExecutionProof::objects($row['intent']['certificates']), $staged)), SORT_REGULAR));
            $allLeaves = array_values(array_unique([...RouteExecutionProof::objects($forward['prepare_certificates']), ...RouteExecutionProof::objects($forward['certificates']), ...RouteExecutionProof::objects($restore['certificates'])], SORT_REGULAR));
            foreach (['forward', 'restore'] as $side) {
                $cleanup = [];
                foreach ($allLeaves as $leaf) {
                    $publication = array_find($publications, static fn (array $node): bool => $node['node_id'] === $leaf['node_id']);
                    if ($publication === null) {
                        throw new \LogicException('Every certificate must have its admitted publication participant.');
                    }
                    $scopes = ValidatedData::stringList(ValidatedData::object($publication[$side === 'forward' ? 'after' : 'before'])['certificate_scopes']);
                    if (! in_array($leaf['scope'], $scopes, true)) {
                        $cleanup[] = array_intersect_key($leaf, array_flip(['node_id', 'user', 'scope', 'home', 'agent_sha256'])) + ['in_use' => false];
                    }
                }
                if ($side === 'forward') {
                    $forward['cleanup'] = $cleanup;
                } else {
                    $restore['cleanup'] = $cleanup;
                }
            }
            $allLan = array_values(array_unique([...RouteExecutionProof::objects($forward['lan']), ...RouteExecutionProof::objects($restore['lan'])], SORT_REGULAR));
            foreach (['forward', 'restore'] as $side) {
                $selected = $side === 'forward' ? $forward : $restore;
                $retire = [];
                foreach ($allLan as $rule) {
                    $key = ValidatedData::integer($rule['node_id']).':'.ValidatedData::integer($rule['route_id']).':'.ValidatedData::string($rule['destination']);
                    $keep = array_values(array_map(static fn (array $kept): string => ValidatedData::string($kept['router']),
                        array_filter(RouteExecutionProof::objects($selected['lan']), static fn (array $kept): bool => $kept['node_id'] === $rule['node_id'] && $kept['route_id'] === $rule['route_id'] && $kept['destination'] === $rule['destination'])));
                    $retire[$key] = ['node_id' => $rule['node_id'], 'route_id' => $rule['route_id'], 'destination' => $rule['destination'], 'operation' => 'retire', 'keep' => $keep];
                }
                if ($side === 'forward') {
                    $forward['retire_lan'] = array_values($retire);
                } else {
                    $restore['retire_lan'] = array_values($retire);
                }
            }
            $certificates = $allLeaves;
            if ($certificates !== []) {
                $participants[] = 209;
            }
            $participants = array_values(array_unique($participants));
            sort($participants);

            return ['route_mutations' => ['schema_version' => 1, 'batch' => $batch, 'analytics_changes' => $analyticsChanges, 'forward' => $forward, 'restore' => $restore],
                'participant_node_ids' => $participants, 'certificates' => $certificates, 'execution_secrets' => $secrets];
        });
    }

    /** @param iterable<Route> $routes
     * @param  array<string,mixed>  $batch
     * @return list<CapturedRoute>
     */
    private function desiredRoutes(iterable $routes, array $batch, bool $staging): array
    {
        $result = [];
        $candidates = [];
        foreach ($routes as $source) {
            $row = array_find(RouteExecutionProof::objects($batch['changes']), static fn (array $row): bool => $row['route_id'] === $source->id);
            $route = clone $source;
            if ($row !== null) {
                $route = Route::query()->with(['targets.instance.node', 'targets.instance.project'])->findOrFail(ValidatedData::integer($row['result_route_id']));
                $placement = ValidatedData::object($row['placement']);
                $route = app(RouteMutationState::class)->candidateWithPlacement($route, new RoutePlacement(
                    $placement['node_id'] === null ? null : ValidatedData::integer($placement['node_id']),
                    $placement['cluster_id'] === null ? null : ValidatedData::integer($placement['cluster_id']), null));
            }
            $route->forceFill(['status' => RouteStatus::Active, 'sites_published' => true, 'replacement_step' => null,
                'replaces_route_id' => null, 'replaced_by_route_id' => null, 'transition_node_id' => null, 'transition_cluster_id' => null]);
            if ($staging && $row !== null) {
                $renamed = $route->id !== $source->id;
                $route->forceFill(['status' => RouteStatus::Activating,
                    'replaces_route_id' => $renamed ? $source->id : null,
                    'replacement_step' => $renamed ? RouteReplacementStep::WorkloadCertificate : RouteReplacementStep::DatabaseCutover,
                    'transition_node_id' => $renamed ? null : $source->node_id,
                    'transition_cluster_id' => $renamed ? null : $source->cluster_id]);
            }
            $candidates[$source->id] = $route;
        }
        foreach ($candidates as $route) {
            $result[] = $this->route($route, $candidates);
        }

        return $result;
    }

    /** @param array<int,Route> $candidates
     * @return CapturedRoute
     */
    private function route(Route $route, array $candidates = []): array
    {
        $intent = $route->isAnalyticsTracking() ? app(AnalyticsRoutePlan::class)->capture($route) : $this->convergence->forRoute($route, ['mode' => 'placement']);
        if ($route->isAnalyticsTracking()) {
            $intent += ['node_ids' => array_values(array_unique([...ValidatedData::integerList($intent['caddy_node_ids']), ...ValidatedData::integerList($intent['dns_node_ids'])])), 'workload_node_ids' => [], 'router_node_id' => null];
        }
        $writes = [];
        foreach (ValidatedData::integerList($intent['workload_node_ids']) as $nodeId) {
            foreach (app(NativeRouteConvergenceEnvironmentMaterial::class)->forRoute($route, $nodeId, $intent, candidates: $candidates) as $write) {
                $writes[] = ['node_id' => $nodeId, ...$write];
            }
        }
        $lan = [];
        $routerId = $intent['router_node_id'];
        if (is_int($routerId)) {
            foreach ($route->targets as $target) {
                $rule = app(RouteHopInput::class)->firewall($target->instance, $route, Node::query()->findOrFail($routerId), $target->instance->node_id);
                if ($rule !== null) {
                    $lan[] = ['node_id' => $target->instance->node_id, 'route_id' => $rule['route_id'], 'operation' => 'converge', 'router' => $rule['router'], 'destination' => $rule['workload']];
                }
            }
        }

        return ['route_id' => $route->id, 'domain' => $route->domain, 'intent' => $intent, 'sites' => $this->sites->routeSites($route), 'url_writes' => $writes, 'lan' => $lan];
    }

    /** @param list<CapturedRoute> $routes
     * @param  list<array<string,mixed>>  $publications
     * @param  array<string,array{vault:string,key:string}>  $secrets
     * @return array<string,mixed>
     */
    private function stage(array $routes, array $publications, string $mode, string $captureId, array &$secrets): array
    {
        $writes = [];
        $certificates = [];
        $lan = [];
        foreach ($routes as $route) {
            $certificates = [...$certificates, ...RouteExecutionProof::objects($route['intent']['certificates'])];
            $lan = [...$lan, ...$route['lan']];
            foreach ($route['url_writes'] as $write) {
                $field = 'route_material_'.count($secrets);
                $private = array_intersect_key($write['input'], array_flip(['content', 'app_key', 'app_name']));
                $public = array_diff_key($write['input'], $private);
                $secrets[$field] = $this->steps->executionCopy('orbit-control-inputs', 'route-'.$captureId.'-'.$mode.'-'.$field, json_encode($private, JSON_THROW_ON_ERROR));
                $writes[] = ['index' => count($writes), 'route_id' => $route['route_id'], 'node_id' => $write['node_id'],
                    'instance_id' => $write['instance_id'], 'name' => $write['name'], 'input' => $public, 'secret_field' => $field];
            }
        }
        $certificates = array_values(array_unique($certificates, SORT_REGULAR));
        $publications = array_map(static function (array $row) use ($mode): array {
            if ($mode === 'restore') {
                $row['connection'] = $row['restore_connection'];
            }
            unset($row['restore_connection']);

            return [...$row, 'direction' => $mode];
        }, $publications);

        return ['routes' => array_map(static fn (array $row): array => ['route_id' => $row['route_id'], 'domain' => $row['domain'], 'node_ids' => $row['intent']['node_ids'],
            'certificate_scopes' => array_map(static fn (array $leaf): array => array_intersect_key($leaf, array_flip(['node_id', 'scope'])), RouteExecutionProof::objects($row['intent']['certificates']))], $routes),
            'certificates' => $certificates, 'url_writes' => $writes, 'publications' => $publications, 'lan' => array_values(array_unique($lan, SORT_REGULAR))];
    }
}
