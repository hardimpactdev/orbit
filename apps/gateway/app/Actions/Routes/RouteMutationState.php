<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Domain\Instances\InstanceSourceProfileGuard;
use App\Domain\Instances\InstanceState;
use App\Domain\Routes\RoutePlacement;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReconciliationGuard;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Shared\StoredInteger;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Route;
use App\Models\RouteTarget;
use Illuminate\Support\Facades\DB;

/** Gateway-owned validation and Route state transitions. Contains no Node execution. */
final readonly class RouteMutationState
{
    /** @param list<int> $expectedTargetIds */
    public function assertTargetsUnchanged(Route $route, array $expectedTargetIds): void
    {
        $currentTargetIds = $route
            ->targets
            ->pluck('instance_id')
            ->map(static fn (mixed $id): int => StoredInteger::from($id))
            ->sort()
            ->values()
            ->all();
        $expectedSorted = $expectedTargetIds;
        sort($expectedSorted);

        if ($currentTargetIds !== $expectedSorted) {
            throw new ResourceOperationException(
                errorCode: 'env.owner_changed',
                message: 'The Instance environment owner changed during the operation.',
                status: 409,
            );
        }
    }

    /** @return list<Instance> */
    public function eligibleTargets(
        Route $route,
        bool $allowRetiring = false,
        ?RoutePublication $publication = null,
        bool $allowGenerated = false,
    ): array {
        $targets = array_values($route->targets
            ->map(static fn (RouteTarget $row): Instance => $row->instance)
            ->all());

        if ($targets === []) {
            app(RouteReconciliationGuard::class)->refuse();
        }

        $environments = array_values(array_unique(array_map(
            static fn (Instance $instance): string => $instance->defaultAppEnv(),
            $targets,
        )));

        $statusAllowed = $route->status === RouteStatus::Active
            || ($allowRetiring && $route->status === RouteStatus::Retiring);

        $effectivePublication = $publication ?? $route->publication;
        $generatedTldChange = $allowGenerated
            && $route->provenance === RouteProvenance::Generated
            && $effectivePublication === RoutePublication::Private
            && count($targets) === 1;

        if (
            ! $statusAllowed
            || (
                ! $generatedTldChange
                && $route->provenance !== RouteProvenance::Explicit
            )
            || ($effectivePublication === RoutePublication::Public && $environments !== ['production'])
            || array_diff($environments, ['development', 'production']) !== []
            || (count($targets) > 1 && $environments !== ['production'])
        ) {
            app(RouteReconciliationGuard::class)->refuse();
        }

        foreach ($targets as $target) {
            if ($target->status !== InstanceState::Active) {
                app(RouteReconciliationGuard::class)->refuse();
            }

            if ($target->source_is_laravel === null) {
                new InstanceSourceProfileGuard()->refuseMissing();
            }
        }

        return $targets;
    }

    public function reserve(
        Route $route,
        string $domain,
        ?RoutePublication $publication = null,
        ?RoutePlacement $placement = null,
    ): Route {
        $reserved = DB::transaction(function () use ($route, $domain, $publication, $placement): Route {
            $locked = Route::query()->with('targets')->lockForUpdate()->findOrFail($route->id);

            if ($locked->replaced_by_route_id !== null) {
                $existing = Route::query()->lockForUpdate()->findOrFail($locked->replaced_by_route_id);

                if ($existing->domain !== $domain) {
                    throw new ResourceOperationException(
                        errorCode: 'route.domain_change_conflict',
                        message: 'The Route already has another domain change in progress.',
                        status: 409,
                    );
                }

                if (
                    $publication instanceof RoutePublication
                    && $existing->publication !== $publication
                ) {
                    throw new ResourceOperationException(
                        errorCode: 'route.domain_change_conflict',
                        message: 'The Route already has another domain change in progress.',
                        status: 409,
                    );
                }

                // A failed replacement has withdrawn its sites and may have removed their
                // certificates, so the identical request restarts it from its first step.
                if ($existing->status === RouteStatus::Failed) {
                    $existing->update([
                        'status' => RouteStatus::Pending,
                        'failed_step' => null,
                        'error_code' => null,
                        'replacement_step' => RouteReplacementStep::Reserved,
                    ]);
                }

                return $existing->refresh()->load('targets');
            }

            $occupied = Route::query()
                ->whereKeyNot($locked->id)
                ->where('domain', $domain)
                ->exists();

            if ($occupied) {
                throw new ResourceOperationException(
                    errorCode: 'route.domain_conflict',
                    message: 'The Route domain is already owned.',
                    status: 409,
                );
            }

            $replacement = Route::query()->create([
                'project_id' => $locked->project_id,
                'node_id' => $placement instanceof RoutePlacement ? $placement->nodeId : $locked->node_id,
                'cluster_id' => $placement instanceof RoutePlacement ? $placement->clusterId : $locked->cluster_id,
                'generation_basis_node_id' => $locked->generation_basis_node_id,
                'domain' => $domain,
                'provenance' => $locked->provenance,
                'publication' => $publication ?? $locked->publication,
                'status' => RouteStatus::Pending,
                'replaces_route_id' => $locked->id,
                'replacement_step' => RouteReplacementStep::Reserved,
            ]);

            $locked->update(['replaced_by_route_id' => $replacement->id]);

            foreach ($locked->targets as $target) {
                $replacement->targets()->create([
                    'instance_id' => $target->instance_id,
                    'position' => $target->position,
                ]);
            }

            return $replacement->refresh()->load(['targets', 'cluster.routerAssignment.node']);
        });

        return $reserved;
    }

    public function candidateWithPlacement(Route $route, RoutePlacement $placement): Route
    {
        // The candidate keeps the Route's id, so the certificate scopes it names are the Route's own.
        $candidate = $route->newInstance([], true);
        $candidate->setRawAttributes($route->getAttributes(), true);
        $candidate->node_id = $placement->nodeId;
        $candidate->cluster_id = $placement->clusterId;
        $candidate->setRelation('targets', $route->targets);

        if ($placement->clusterId === null) {
            $candidate->setRelation('cluster', null);

            return $candidate;
        }

        $candidate->setRelation(
            'cluster',
            Cluster::query()->with('routerAssignment.node')->find($placement->clusterId),
        );

        return $candidate;
    }

    public function cutoverPlacement(Route $route, RoutePlacement $placement): void
    {
        DB::transaction(function () use ($route, $placement): void {
            $locked = Route::query()->lockForUpdate()->findOrFail($route->id);
            $locked->update([
                'transition_node_id' => $locked->node_id,
                'transition_cluster_id' => $locked->cluster_id,
                'transition_dns_moved_at' => null,
                'node_id' => $placement->nodeId,
                'cluster_id' => $placement->clusterId,
                'replacement_step' => RouteReplacementStep::DatabaseCutover,
                'failed_step' => null,
                'error_code' => null,
            ]);
            $route->setRawAttributes($locked->refresh()->getAttributes(), true);
        });
    }

    public function recordDnsMoved(Route $route): void
    {
        Route::query()->whereKey($route->id)->update(['transition_dns_moved_at' => now()]);
    }

    public function storeTransition(Route $route, ?int $nodeId, ?int $clusterId): void
    {
        DB::transaction(function () use ($route, $nodeId, $clusterId): void {
            $locked = Route::query()->lockForUpdate()->findOrFail($route->id);
            $locked->update([
                'transition_node_id' => $nodeId,
                'transition_cluster_id' => $clusterId,
                'transition_dns_moved_at' => null,
            ]);
            $route->setRawAttributes($locked->refresh()->getAttributes(), true);
        });
    }

    public function cutover(Route $current, Route $replacement): void
    {
        DB::transaction(function () use ($current, $replacement): void {
            $lockedCurrent = Route::query()->lockForUpdate()->findOrFail($current->id);
            $lockedReplacement = Route::query()->lockForUpdate()->findOrFail($replacement->id);

            if (
                $lockedCurrent->replaced_by_route_id !== $lockedReplacement->id
                || $lockedReplacement->replaces_route_id !== $lockedCurrent->id
            ) {
                throw new ResourceOperationException(
                    errorCode: 'route.domain_change_conflict',
                    message: 'The Route domain change evidence changed during convergence.',
                    status: 409,
                );
            }

            $lockedCurrent->update(['status' => RouteStatus::Retiring]);
            $lockedReplacement->update([
                'status' => RouteStatus::Activating,
                'replacement_step' => RouteReplacementStep::DatabaseCutover,
                'failed_step' => null,
                'error_code' => null,
            ]);
            $current->setRawAttributes($lockedCurrent->refresh()->getAttributes(), true);
            $replacement->setRawAttributes($lockedReplacement->refresh()->getAttributes(), true);
        });
    }

    public function checkpoint(Route $route, RouteReplacementStep $step): void
    {
        DB::transaction(function () use ($route, $step): void {
            $locked = Route::query()->lockForUpdate()->findOrFail($route->id);
            $locked->update([
                'replacement_step' => $step,
                'failed_step' => null,
                'error_code' => null,
            ]);
            $route->setRawAttributes($locked->refresh()->getAttributes(), true);
        });
    }

    public function recordFailure(Route $route, string $step, string $errorCode): void
    {
        Route::query()
            ->whereKey($route->id)
            ->update([
                'failed_step' => $step,
                'error_code' => $errorCode,
            ]);
        $route->refresh();
    }
}
