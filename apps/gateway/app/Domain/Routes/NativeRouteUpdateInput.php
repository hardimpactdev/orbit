<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Analytics\AnalyticsRoutePlan;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Route;
use App\Support\ValidatedData;
use Closure;

/** Freeze standalone update intent without changing the serving Route. */
final readonly class NativeRouteUpdateInput
{
    public function __construct(private NativeRouteMutationInput $publications, private DevelopmentSiteRepository $sites) {}

    /** The caller holds the Route and Instance admission locks.
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function capture(Route $route, array $input): array
    {
        $candidate = isset($input['candidate_route_id'])
            ? Route::query()->with(['targets.instance.node', 'targets.instance.project'])->findOrFail(ValidatedData::integer($input['candidate_route_id']))
            : clone $route;
        $candidate->forceFill(['publication' => $input['publication'], 'web_root' => $input['web_root']]);
        $mode = ValidatedData::string($input['mode']);
        $changed = $candidate->id !== $route->id || $candidate->publication !== $route->publication || $candidate->web_root !== $route->web_root;
        $effectful = $changed && $mode !== 'replace_pending' && ($route->sites_published || $route->status->isAuthoritative());
        $before = array_intersect_key($route->getAttributes(), array_flip(['status', 'sites_published', 'publication', 'web_root', 'replacement_step', 'failed_step', 'error_code']));
        $selected = ['publication' => $candidate->publication->value, 'web_root' => $candidate->web_root];
        $metadata = ['route_id' => $route->id, 'result_route_id' => $candidate->id, 'mode' => $mode,
            'effectful' => $effectful, 'workloads' => [], 'before' => $before, 'selected' => $selected,
            'public_activated' => new PublicRouteEligibility()->canStartActivation($candidate)];
        if (! $effectful) {
            return ['intent' => $metadata, 'certificates' => [], 'participant_node_ids' => [209], 'execution_secrets' => []];
        }

        $beforeSites = $this->sites->all();
        $previous = [$this->publications->route($route)];
        $candidate->forceFill(['status' => RouteStatus::Active, 'sites_published' => true,
            'replacement_step' => $candidate->publication === RoutePublication::Public && new PublicRouteEligibility()->canStartActivation($candidate)
                ? RouteReplacementStep::Cleanup : null,
            'replaces_route_id' => null, 'replaced_by_route_id' => null]);
        $desired = [$this->publications->route($candidate, [$route->id => $candidate])];
        $staging = clone $candidate;
        if ($candidate->id !== $route->id) {
            $staging->forceFill(['status' => RouteStatus::Activating, 'replaces_route_id' => $route->id,
                'replacement_step' => RouteReplacementStep::WorkloadCertificate]);
        }
        $staged = [$this->publications->route($staging, [$route->id => $staging])];
        $analytics = app(AnalyticsRoutePlan::class)->captureUpdate($route, $candidate);
        foreach ($analytics as $change) {
            $tracking = Route::query()->findOrFail($change['route_id']);
            $previous[] = $this->publications->route($tracking);
            $next = clone $tracking;
            $next->unsetRelations();
            $next->forceFill($change['after']);
            $next->replacement_step = RouteReplacementStep::Cleanup;
            $desired[] = $this->publications->route($next);
            $next->replacement_step = null;
            $staged[] = $this->publications->route($next);
        }
        $capture = $this->publications->capture($previous, $desired, $staged, $previous,
            $beforeSites, $beforeSites, [], static fn (Closure $render): array => $render());
        if ($mode === 'web_root') {
            foreach ($route->targets as $target) {
                $metadata['workloads'][] = app(NativeRouteWorkloadPreparation::class)->capture($target->instance, $route, $candidate);
            }
        }
        $capture['intent'] = [...$metadata, 'analytics_changes' => $analytics, 'route_mutations' => $capture['route_mutations']];
        unset($capture['route_mutations']);

        return $capture;
    }
}
