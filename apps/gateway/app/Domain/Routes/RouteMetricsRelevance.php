<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Models\Route;
use App\Support\ValidatedData;

/** Compares the public host intent changed by this Route parent, without Node effects. */
final readonly class RouteMetricsRelevance
{
    /** @param array<string, mixed> $plan */
    public function changes(Route $route, string $mode, array $plan): bool
    {
        $before = [$route];
        $after = clone $route;
        if ($mode === 'publication') {
            $after->publication = RoutePublication::from(ValidatedData::string($plan['publication']));
            if (($plan['activate_public'] ?? false) === true) {
                $after->status = RouteStatus::Active;
            }
        } elseif ($mode === 'converge') {
            $after->status = RouteStatus::Active;
        } elseif (in_array($mode, ['withdraw', 'withdraw_replacement'], true)) {
            return $this->hosts($before) !== [];
        } elseif (in_array($mode, ['replacement', 'replacement_after_cutover', 'replacement_cleanup', 'replace_pending'], true)) {
            $candidate = Route::query()->findOrFail(ValidatedData::integer($plan['candidate_route_id']));
            $before[] = $candidate;
            $after = clone $candidate;
            $after->status = $mode === 'replace_pending' ? RouteStatus::Pending : RouteStatus::Active;
        } else {
            // Targets and paths do not enter the Metrics Caddy host catalog. FPM selection is separate.
            return false;
        }

        return $this->hosts($before) !== $this->hosts([$after]);
    }

    /** @param list<Route> $routes
     * @return list<array{int, string}>
     */
    private function hosts(array $routes): array
    {
        $hosts = [];
        foreach ($routes as $route) {
            if ($route->publication !== RoutePublication::Public || $route->status !== RouteStatus::Active) {
                continue;
            }
            $ingressId = $route->cluster?->ingressAssignment?->node_id;
            if ($ingressId !== null) {
                $hosts[] = [$ingressId, $route->domain];
            }
        }
        $hosts = array_values(array_unique($hosts, SORT_REGULAR));
        usort($hosts, static fn (array $first, array $second): int => $first <=> $second);

        return $hosts;
    }
}
