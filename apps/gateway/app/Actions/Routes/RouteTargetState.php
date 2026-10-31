<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Data\Routes\RouteTargetDispositionData;
use App\Data\Routes\SetRouteTargetsData;
use App\Domain\Routes\RouteAssociationGuard;
use App\Domain\Routes\RouteExecutionProof;
use App\Domain\Routes\RouteReconciliationGuard;
use App\Domain\Routes\RouteStatus;
use App\Domain\Routes\RouteTargetSetGuard;
use App\Domain\Routes\RouteTargetSetStep;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Shared\StoredInteger;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Route;
use App\Models\RouteTarget;
use Illuminate\Support\Facades\DB;

/** Gateway-owned validation and Route state transitions. Contains no Node execution. */
final readonly class RouteTargetState
{
    public function __construct(private RouteTargetSetGuard $guard) {}

    /** @param list<int> $expectedTargetIds */
    public function clear(Route $route, array $expectedTargetIds): Route
    {
        if (! $route->isApp()) {
            throw new ResourceOperationException('route.kind_unsupported', 'Only a Project Route can own Instance targets.', 409);
        }
        $locked = Route::query()->lockForUpdate()->findOrFail($route->id);
        $current = $locked->targets()->orderBy('instance_id')->pluck('instance_id')->map(StoredInteger::from(...))->values()->all();
        if ($current !== $expectedTargetIds) {
            throw new ResourceOperationException('env.owner_changed', 'The Instance environment owner changed during the operation.', 409);
        }
        if ($current !== []) {
            if ($locked->isUnactivatedReplacement()) {
                throw new ResourceOperationException('route.target_conflict', "Route [{$locked->id}] is a replacement that never activated. Remove it with route:destroy; the Route it replaces keeps its target.", 409);
            }
            app(RouteAssociationGuard::class)->assertTargetsDetachable($locked);
            app(RouteReconciliationGuard::class)->assertRouteMutable($locked);
            $locked->targets()->delete();
        }

        return $locked->load('targets');
    }

    /** @param list<int> $ids
     * @return list<array{instance_id: int, node_id: int, checkout_path: string}>
     */
    public function detachedInstances(array $ids): array
    {
        return array_values($this->guard->instances($ids)->map(static fn (Instance $instance): array => [
            'instance_id' => $instance->id, 'node_id' => $instance->node_id, 'checkout_path' => $instance->checkout_path,
        ])->all());
    }

    public function committed(Route $route): bool
    {
        return in_array($route->target_set_step, ['database-committed', 'environment-synchronized', 'router-published', 'vacated-published', 'removal'], true);
    }

    public function recovery(Route $route): Operation
    {
        $id = $route->target_set_intent['preparation_operation_id'] ?? null;
        $previous = is_int($id) ? Operation::query()->find($id) : null;
        if ($previous === null || $previous->kind !== 'route.target_set' || $previous->target_type !== 'route' || $previous->target_id !== $route->id
            || ! is_array($previous->state['route_plan'] ?? null)
            || (RouteExecutionProof::result($previous, 'stage-commit.database-committed')['ready'] ?? false) !== true) {
            throw new ResourceOperationException('route.target_set_conflict', 'The committed target set needs its recorded preparation and database proof.', 409);
        }

        return $previous;
    }

    public function assertCommitted(Route $route, SetRouteTargetsData $proposal): void
    {
        $current = $route->targets()->pluck('instance_id')->map(StoredInteger::from(...))->all();
        $expected = $proposal->targetIds;
        foreach ($proposal->dispositions as $disposition) {
            $instance = Instance::query()->find($disposition->instanceId);
            if ($instance === null) {
                if ($disposition->remove) {
                    continue;
                }
                throw new ResourceOperationException('route.target_conflict', 'A retained target disappeared after commit.', 409);
            }
            if ($disposition->remove) {
                $expected[] = $instance->id;
            } elseif (! RouteTarget::query()->where('instance_id', $instance->id)->where('route_id', $disposition->routeId)->exists()) {
                throw new ResourceOperationException('route.target_conflict', 'A committed target reassignment changed.', 409);
            }
        }
        sort($current);
        sort($expected);
        if ($current !== $expected) {
            throw new ResourceOperationException('route.target_conflict', 'The committed target associations changed.', 409);
        }
    }

    public function isCompletedNoop(Route $route, SetRouteTargetsData $proposal): bool
    {
        if ($route->target_set_intent !== null) {
            return false;
        }

        $current = $route
            ->targets()
            ->orderBy('position')
            ->pluck('instance_id')
            ->map(static fn (mixed $id): int => StoredInteger::from($id))
            ->values()
            ->all();

        return $current === $proposal->targetIds;
    }

    public function reserve(Route $route, SetRouteTargetsData $proposal): void
    {
        if (is_array($route->target_set_intent)) {
            return;
        }

        $route->update([
            'target_set_intent' => $proposal->toIntent(),
            'target_set_step' => RouteTargetSetStep::Reserved->value,
            'failed_step' => null,
            'error_code' => null,
        ]);
        $route->refresh();
    }

    public function commitAssociations(Route $route, SetRouteTargetsData $proposal): void
    {
        DB::transaction(function () use ($route, $proposal): void {
            $locked = Route::query()->lockForUpdate()->findOrFail($route->id);
            $this->guard->assertProposal($locked, $proposal);
            $vacatedRouteIds = $this->vacatedRouteIds($locked, $proposal);
            $removeIds = [];
            $reassignments = [];

            foreach ($proposal->dispositions as $disposition) {
                if ($disposition->remove) {
                    $removeIds[] = $disposition->instanceId;
                } elseif ($disposition->routeId !== null) {
                    $reassignments[$disposition->instanceId] = $disposition->routeId;
                }
            }

            foreach ($proposal->targetIds as $instanceId) {
                $this->associateOnRoute($locked, $instanceId);
            }

            foreach ($reassignments as $instanceId => $destinationId) {
                $this->reassignToRoute($instanceId, $destinationId);
            }

            $this->compactPositions($locked->id, [...$proposal->targetIds, ...$removeIds]);

            foreach ($vacatedRouteIds as $vacatedRouteId) {
                $this->compactPositions($vacatedRouteId);
            }
        });
    }

    public function associateOnRoute(Route $route, int $instanceId): void
    {
        $existing = RouteTarget::query()
            ->where('instance_id', $instanceId)
            ->lockForUpdate()
            ->first();

        if ($existing instanceof RouteTarget && $existing->route_id === $route->id) {
            return;
        }

        $position = RouteTarget::query()->where('route_id', $route->id)->count();

        if ($existing instanceof RouteTarget) {
            $existing->update([
                'route_id' => $route->id,
                'position' => $position,
            ]);

            return;
        }

        RouteTarget::query()->create([
            'route_id' => $route->id,
            'instance_id' => $instanceId,
            'position' => $position,
        ]);
    }

    public function reassignToRoute(int $instanceId, int $destinationId): void
    {
        $existing = RouteTarget::query()
            ->where('instance_id', $instanceId)
            ->lockForUpdate()
            ->first();

        if (! $existing instanceof RouteTarget || $existing->route_id === $destinationId) {
            return;
        }

        $destination = Route::query()->lockForUpdate()->findOrFail($destinationId);
        $existing->update([
            'route_id' => $destination->id,
            'position' => RouteTarget::query()->where('route_id', $destination->id)->count(),
        ]);

        if ($destination->status === RouteStatus::Pending) {
            $destination->update(['status' => RouteStatus::Active]);
        }
    }

    /** @param list<int>|null $orderedInstanceIds */
    public function compactPositions(int $routeId, ?array $orderedInstanceIds = null): void
    {
        if ($orderedInstanceIds !== null) {
            $rows = RouteTarget::query()
                ->where('route_id', $routeId)
                ->lockForUpdate()
                ->get()
                ->keyBy('instance_id');
            $position = 0;

            foreach ($orderedInstanceIds as $instanceId) {
                $row = $rows->get($instanceId);

                if (! $row instanceof RouteTarget) {
                    continue;
                }

                if ($row->position !== $position) {
                    $row->update(['position' => $position]);
                }

                $position++;
            }

            return;
        }

        $rows = RouteTarget::query()
            ->where('route_id', $routeId)
            ->lockForUpdate()
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->values();

        foreach ($rows as $position => $row) {
            if ($row->position !== $position) {
                $row->update(['position' => $position]);
            }
        }
    }

    /** @return list<int> */
    public function vacatedRouteIds(Route $route, SetRouteTargetsData $proposal): array
    {
        $ids = [];

        foreach ($proposal->targetIds as $instanceId) {
            $association = RouteTarget::query()
                ->where('instance_id', $instanceId)
                ->where('route_id', '!=', $route->id)
                ->first();

            if ($association !== null) {
                $ids[] = (int) $association->route_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** @return list<array{Instance, Route}> */
    public function preparedInstances(Route $route, SetRouteTargetsData $proposal): array
    {
        $prepared = [];

        foreach ($this->guard->instances($proposal->targetIds) as $instance) {
            $prepared[] = [$instance, $route];
        }

        foreach ($proposal->dispositions as $disposition) {
            if ($disposition->remove || $disposition->routeId === null) {
                continue;
            }

            $instance = $this->guard->instances([$disposition->instanceId])->first();
            $destination = Route::query()->findOrFail($disposition->routeId);

            if ($instance instanceof Instance) {
                $prepared[] = [$instance, $destination];
            }
        }

        return $prepared;
    }

    /** @return list<Instance> */
    public function synchronizedInstances(Route $route, SetRouteTargetsData $proposal): array
    {
        $ids = $proposal->targetIds;

        foreach ($proposal->dispositions as $disposition) {
            if (! $disposition->remove) {
                $ids[] = $disposition->instanceId;
            }
        }

        return array_values($this->guard->instances(array_values(array_unique($ids)))->all());
    }

    public function complete(Route $route): void
    {
        $route->update([
            'target_set_intent' => null,
            'target_set_step' => RouteTargetSetStep::Completed->value,
            'failed_step' => null,
            'error_code' => null,
        ]);
    }

    public function advance(Route $route, RouteTargetSetStep $step): void
    {
        $route->update([
            'target_set_step' => $step->value,
            'failed_step' => null,
            'error_code' => null,
        ]);
        $route->refresh();
    }

    public function recordFailure(Route $route, string $step, string $errorCode): void
    {
        $route->update([
            'failed_step' => $step,
            'error_code' => $errorCode,
        ]);
    }

    /** @return list<int> */
    public function ownerIds(Route $route, SetRouteTargetsData $proposal): array
    {
        $ids = [
            ...$route->targets->pluck('instance_id')->map(static fn (mixed $id): int => StoredInteger::from($id))->all(),
            ...$proposal->targetIds,
            ...array_map(
                static fn (RouteTargetDispositionData $disposition): int => $disposition->instanceId,
                $proposal->dispositions,
            ),
        ];
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
