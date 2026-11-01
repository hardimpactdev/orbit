<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Actions\Routes\RouteMutationState;
use App\Domain\Analytics\AnalyticsRoutePlan;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Node;
use App\Models\Route;
use App\Support\ValidatedData;
use Closure;
use Illuminate\Support\Facades\DB;

/** Complete Route supplier for the owning Node admission; no Operation or Node mutation.
 * @phpstan-type CapturedRoute array{route_id:int,domain:string,intent:array<string,mixed>,sites:list<DevelopmentSite>,url_writes:list<array{node_id:int,instance_id:int,name:string,input:array<string,mixed>}>}
 */
final readonly class NativeRouteNodeMutationInput
{
    public function __construct(private NativeRouteMutationPlan $mutations, private RouteMutationReconciler $reconciler,
        private DevelopmentSiteRepository $sites) {}

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
            $desired = $this->render($candidate, fn (): array => $this->desiredRoutes($routes, $batch, false));
            $staged = $this->render($candidate, fn (): array => $this->desiredRoutes($routes, $batch, true));
            $forwardPrior = $this->render($candidate, function () use ($routes): array {
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
                    $desired[] = $this->render($candidate, fn (): array => $this->route($candidateTracking));
                    $candidateTracking->replacement_step = null;
                    $staged[] = $this->render($candidate, fn (): array => $this->route($candidateTracking));
                    $forwardPrior[] = $this->render($candidate, fn (): array => $this->route($tracking));
                }
            }
            $capture = app(NativeRouteMutationInput::class)->capture($previous, $desired, $staged, $forwardPrior,
                $beforeSites, $this->render($candidate, fn () => $this->sites->all()),
                ValidatedData::integerList($batch['node_ids']),
                fn (Closure $render): array => $this->render($candidate, $render));
            $capture['route_mutations'] += ['batch' => $batch, 'analytics_changes' => $analyticsChanges];

            return $capture;
        });
    }

    /** Prospective publications follow the parent's captured role certificate actions.
     * @template T
     *
     * @param  Closure():T  $render
     * @return T
     */
    private function render(NativeRouteNodeRender $candidate, Closure $render): mixed
    {
        $context = app(RouteRenderContext::class);

        return $context->render($candidate,
            fn () => $context->renderRoleCertificates($candidate->roleCertificates, $render));
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
        return app(NativeRouteMutationInput::class)->route($route, $candidates);
    }
}
