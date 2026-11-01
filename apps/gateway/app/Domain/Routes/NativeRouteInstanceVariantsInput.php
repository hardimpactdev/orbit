<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Instances\InstancePhpVersionCatalog;
use App\Domain\Instances\InstanceState;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Instance;
use App\Models\Route;
use App\Support\ValidatedData;
use Closure;

/** Finite initial publications only; the create parent owns the whole environment and primary URL. */
final readonly class NativeRouteInstanceVariantsInput
{
    public function __construct(private NativeRouteMutationInput $mutations, private DevelopmentSiteRepository $sites,
        private InstancePhpVersionCatalog $versions, private RouteRenderContext $context) {}

    /** The parent holds admission ownership and supplies the existing actual identities.
     * @return array<string,array{route_mutations:array<string,mixed>,participant_node_ids:list<int>,certificates:list<array<string,mixed>>,execution_secrets:array<string,array{vault:string,key:string}>}>
     */
    public function capture(Instance $actual, Route $reservedRoute): array
    {
        $actual = Instance::query()->findOrFail($actual->id);
        $route = Route::query()->with(['targets.instance.node', 'targets.instance.project', 'cluster.routerAssignment.node', 'cluster.ingressAssignment.node'])->findOrFail($reservedRoute->id);
        if (! $route->isApp() || $route->status !== RouteStatus::Pending || $route->sites_published
            || $route->project_id !== $actual->project_id
            || $route->targets->count() !== 1 || $route->targets->sole()->instance_id !== $actual->id
            || ! in_array($actual->status, [InstanceState::Reserved, InstanceState::CheckoutPrepared, InstanceState::SourceResolved], true)) {
            throw new ResourceOperationException('route.source_variant_invalid', 'Source variants require the prospective Instance and its reserved unpublished Route.', 409);
        }

        return $this->context->renderActual(function () use ($actual, $route): array {
            $beforeSites = $this->sites->all();
            $prior = $this->mutations->route($route, environment: false);
            $prior['sites'] = array_values($beforeSites->where('domain', $route->domain)->all());
            $prior['intent']['certificates'] = [];
            $candidate = clone $route;
            $candidate->forceFill(['status' => RouteStatus::Active, 'sites_published' => true,
                'replacement_step' => app(PublicRouteEligibility::class)->canStartActivation($route) ? RouteReplacementStep::Cleanup : null]);
            $variants = [];
            foreach ($this->versions->versions() as $version) {
                foreach ([true, false] as $laravel) {
                    $facts = new NativeRouteInstanceFacts($actual->id, $actual->node_id, $version, $laravel);
                    $variants[$facts->key()] = $this->context->renderInstanceFacts($facts, function () use ($route, $candidate, $prior, $beforeSites): array {
                        $desired = $this->mutations->route($candidate, [$route->id => $candidate], environment: false);

                        return $this->mutations->capture([$prior], [$desired], [$desired], [$prior], $beforeSites, $beforeSites,
                            ValidatedData::integerList($prior['intent']['node_ids']), static fn (Closure $render): array => $render());
                    });
                }
            }

            return $variants;
        });
    }
}
