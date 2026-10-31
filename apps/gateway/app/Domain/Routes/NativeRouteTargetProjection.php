<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Actions\Routes\RouteTargetState;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationPublicView;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Route;
use App\Models\RouteTarget;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Association and status writes selected by the native DAG. No effect-proof validation. */
final readonly class NativeRouteTargetProjection implements DirectOperationProjection, DirectOperationPublicView
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        return $this->publicInput($operation, ValidatedData::integer($operation->node_id));
    }

    public function assertPublicParticipant(Operation $operation, int $callerNodeId): void
    {
        if (! in_array($callerNodeId, ValidatedData::integerList($operation->input['participant_node_ids']), true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'This Node is not an admitted target-set participant.', 403);
        }
    }

    public function publicInput(Operation $operation, int $callerNodeId): array
    {
        $this->assertPublicParticipant($operation, $callerNodeId);

        return [...app(NativeRouteTargetIntent::class)->publicInput($operation, $callerNodeId), 'participant_node_id' => $callerNodeId];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation->setRawAttributes(Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail()->getAttributes(), true);
            $terminal = $operation->status->terminal();
            $result = ValidatedData::object($data['result'] ?? []);
            $operation = $this->direct->project($operation, $data, $result);
            if ($terminal) {
                return $operation;
            }
            if (isset($result['checkpoint'])) {
                if ($operation->status !== OperationStatus::Running) {
                    throw $this->invalid();
                }
                $this->project($operation, $result);
            } elseif ($operation->status === OperationStatus::Succeeded) {
                $this->project($operation, ['ready' => true, 'route_id' => $operation->target_id]);
            } elseif ($operation->status === OperationStatus::Failed) {
                app(RouteTargetState::class)->recordFailure(Route::query()->findOrFail($operation->target_id), 'native-target-set', $operation->error_code ?? 'route.target_set_failed');
            }

            return $operation;
        });
    }

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
                'rollback-associations' => $this->restore($operation, $route, $intent),
                'environment-synchronized' => $state->advance($route, RouteTargetSetStep::EnvironmentSynchronized),
                'router-published' => $state->advance($route, RouteTargetSetStep::RouterPublished),
                'vacated-published' => $state->advance($route, RouteTargetSetStep::VacatedPublished),
                'removal' => $state->advance($route, RouteTargetSetStep::Removal),
                default => throw $this->invalid(),
            };
            if ($result['checkpoint'] === 'rollback-preparations') {
                $operation->update(['state' => [...($operation->state ?? []), 'native_target_rollback' => true]]);
            }

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
    private function restore(Operation $operation, Route $route, array $intent): void
    {
        $proposal = NativeRouteTargetIntent::proposal(ValidatedData::object($intent['proposal']));
        $original = RouteExecutionProof::objects($intent['original_associations']);
        $ids = [...$proposal->targetIds, ...array_column($proposal->toIntent()['dispositions'], 'instance_id'), ...ValidatedData::integerList(array_column($original, 'instance_id'))];
        RouteTarget::query()->whereIn('instance_id', array_values(array_unique($ids)))->whereNotIn('instance_id', array_column($original, 'instance_id'))->delete();
        foreach ($original as $association) {
            RouteTarget::query()->updateOrCreate(['instance_id' => ValidatedData::integer($association['instance_id'])],
                ['route_id' => ValidatedData::integer($association['route_id']), 'position' => ValidatedData::integer($association['position'])]);
        }
        foreach (RouteExecutionProof::objects($intent['original_route_states']) as $owner) {
            Route::query()->whereKey(ValidatedData::integer($owner['route_id']))->update(['status' => RouteStatus::from(ValidatedData::string($owner['status'])), 'sites_published' => $owner['sites_published']]);
        }
        $route->update(['target_set_step' => RouteTargetSetStep::Reserved->value]);
        $operation->update(['state' => [...($operation->state ?? []), 'native_target_rollback' => true, 'native_target_preparations' => false]]);
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
            foreach (ValidatedData::integerList($intent['vacated_route_ids']) as $id) {
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
