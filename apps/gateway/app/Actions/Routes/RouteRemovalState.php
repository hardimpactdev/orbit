<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\InstanceState;
use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Routes\PublicRouteEligibility;
use App\Domain\Routes\RouteAssociationGuard;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteReconciliationGuard;
use App\Domain\Routes\RouteRemovalNode;
use App\Domain\Routes\RouteRemovalProjector;
use App\Domain\Routes\RouteRemovalStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Shared\StoredInteger;
use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Route;
use App\Models\RouteRemovalResidue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class RouteRemovalState
{
    public function __construct(private RouteReconciliationGuard $reconciliation) {}
    public function lockAndGuard(Route $route, array $expectedTargetIds): Route
    {
        $locked = DB::transaction(function () use ($route, $expectedTargetIds): Route {
            $locked = Route::query()->with('targets')->lockForUpdate()->findOrFail($route->id);
            $this->assertTargetsUnchanged($locked, $expectedTargetIds);
            $this->assertNoOpenTransfer($locked);

            return $locked;
        });

        return $locked;
    }

    public function assertNoOpenTransfer(Route $route): void
    {
        if (InstanceTransfer::query()->open()->where(static fn (Builder $query): Builder => $query
            ->where('source_route_id', $route->id)
            ->orWhere('destination_route_id', $route->id))->exists()) {
            throw new ResourceOperationException(
                errorCode: 'instance.transfer_incomplete',
                message: 'An open Instance transfer owns this Route. Retry the identical transfer request first.',
                status: 409,
            );
        }
    }

    public function assertStandaloneRemovalAllowed(Route $route, bool $allowTracking): void
    {
        if ($route->kind === RouteKind::AnalyticsTracking && ! $allowTracking) {
            throw new ResourceOperationException(
                errorCode: 'route.tracking_managed',
                message: 'Tracking Routes are managed by the analytics role and cannot be destroyed directly.',
                status: 409,
            );
        }

        if (! $route->isCustomProxy() && new PublicRouteEligibility()->publicEdgeIsLive($route)) {
            $this->reconciliation->refuse();
        }

        if ($route->replaces_route_id !== null || $route->replaced_by_route_id !== null) {
            $this->reconciliation->refuse();
        }
    }

    public function beginRemoval(Route $route): void
    {
        if ($route->status === RouteStatus::Retiring && ! $route->sites_published) {
            return;
        }

        DB::transaction(function () use ($route): void {
            $locked = Route::query()->lockForUpdate()->findOrFail($route->id);

            if ($locked->status === RouteStatus::Retiring && ! $locked->sites_published) {
                $route->setRawAttributes($locked->refresh()->getAttributes(), true);

                return;
            }

            $locked->update([
                'status' => RouteStatus::Retiring,
                'sites_published' => false,
            ]);
            $route->setRawAttributes($locked->refresh()->getAttributes(), true);
        });
    }

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
}
