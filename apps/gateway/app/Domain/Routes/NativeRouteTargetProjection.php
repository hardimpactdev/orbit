<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Actions\Routes\RouteTargetState;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Association and status writes selected by the native DAG. No effect-proof validation. */
final readonly class NativeRouteTargetProjection
{
    /** @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    public function project(Operation $operation, array $result): array
    {
        $intent = ValidatedData::object($operation->input['route_target'] ?? []);
        $routeId = ValidatedData::integer($intent['route_id']);
        if ($operation->kind !== 'route.target_set' || $operation->target_type !== 'route' || $operation->target_id !== $routeId) {
            throw $this->invalid();
        }
        $route = Route::query()->findOrFail($routeId);
        if (isset($result['checkpoint'])) {
            if (count($result) !== 1) {
                throw $this->invalid();
            }
            $state = app(RouteTargetState::class);
            match ($result['checkpoint']) {
                'preparations-published' => $this->preparations($operation, $route, true),
                'rollback-preparations', 'live-certificates' => $this->preparations($operation, $route, false),
                'database-committed' => $this->associations($route, $intent),
                'environment-synchronized' => $state->advance($route, RouteTargetSetStep::EnvironmentSynchronized),
                'router-published' => $state->advance($route, RouteTargetSetStep::RouterPublished),
                'vacated-published' => $state->advance($route, RouteTargetSetStep::VacatedPublished),
                'removal' => $state->advance($route, RouteTargetSetStep::Removal),
                default => throw $this->invalid(),
            };

            return $result;
        }
        if (($result['route_id'] ?? null) !== $routeId) {
            throw $this->invalid();
        }
        if (($result['ready'] ?? false) === true && count($result) === 2) {
            if (! $intent['binding_only'] && ! $intent['noop']) {
                app(RouteTargetState::class)->complete($route);
            }

            return ['ready' => true, 'route_id' => $routeId];
        }
        $code = $result['error_code'] ?? null;
        if (! is_string($code) || preg_match('/\A[a-z0-9_.-]{1,100}\z/D', $code) !== 1 || count($result) !== 2) {
            throw $this->invalid();
        }
        app(RouteTargetState::class)->recordFailure($route, 'native-target-set', $code);

        return ['route_id' => $routeId, 'error_code' => $code];
    }

    private function preparations(Operation $operation, Route $route, bool $published): void
    {
        $operation->update(['state' => [...($operation->state ?? []), 'native_target_preparations' => $published]]);
        $route->update(['target_set_intent' => [...($route->target_set_intent ?? []), 'preparation_operation_id' => $operation->id]]);
    }

    /** @param array<string,mixed> $intent */
    private function associations(Route $route, array $intent): void
    {
        DB::transaction(function () use ($route, $intent): void {
            $state = app(RouteTargetState::class);
            $locked = Route::query()->lockForUpdate()->findOrFail($route->id);
            $proposal = NativeRouteTargetIntent::proposal(ValidatedData::object($intent['proposal']));
            $removed = [];
            foreach ($proposal->targetIds as $id) {
                $state->associateOnRoute($locked, $id);
            }
            foreach ($proposal->dispositions as $disposition) {
                if ($disposition->remove) {
                    $removed[] = $disposition->instanceId;
                } elseif ($disposition->routeId !== null) {
                    $state->reassignToRoute($disposition->instanceId, $disposition->routeId);
                }
            }
            $state->compactPositions($route->id, [...$proposal->targetIds, ...$removed]);
            foreach ($intent['vacated_route_ids'] as $id) {
                $state->compactPositions(ValidatedData::integer($id));
            }
            $state->advance($locked, RouteTargetSetStep::DatabaseCommitted);
        });
    }

    private function invalid(): ResourceOperationException
    {
        return new ResourceOperationException('operation.result_invalid', 'The target-set outcome must name its admitted Route.', 422);
    }
}
