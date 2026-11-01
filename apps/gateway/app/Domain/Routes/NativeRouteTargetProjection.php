<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Actions\Routes\RouteTargetState;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Commit admitted associations only after the shared reader observes native terminal success. */
final readonly class NativeRouteTargetProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        return ['intent' => $operation->input['route_target'], 'execution_secrets' => $operation->input['execution_secrets']];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $wasTerminal = $operation->status->terminal();
            $result = ValidatedData::object($data['result'] ?? []);
            $operation = $this->direct->project($operation, $data, $result);
            if ($wasTerminal || ! $operation->status->terminal()) {
                return $operation;
            }
            $intent = ValidatedData::object($operation->input['route_target']);
            $route = Route::query()->lockForUpdate()->findOrFail(ValidatedData::integer($intent['route_id']));
            $state = app(RouteTargetState::class);
            if ($operation->status === OperationStatus::Succeeded) {
                if (! $intent['binding_only'] && ! $intent['noop']) {
                    $proposal = NativeRouteTargetIntent::proposal(ValidatedData::object($intent['proposal']));
                    foreach ($proposal->targetIds as $id) {
                        $state->associateOnRoute($route, $id);
                    }
                    foreach ($proposal->dispositions as $disposition) {
                        if ($disposition->routeId !== null) {
                            $state->reassignToRoute($disposition->instanceId, $disposition->routeId);
                        }
                    }
                    $state->compactPositions($route->id, $proposal->targetIds);
                    foreach (ValidatedData::integerList(array_column(RouteExecutionProof::objects($intent['original_route_states']), 'route_id')) as $id) {
                        if ($id !== $route->id) {
                            $state->compactPositions($id);
                        }
                    }
                    $route->update(['status' => RouteStatus::Active, 'sites_published' => true]);
                    $state->complete($route);
                }
            } elseif ($operation->status === OperationStatus::Failed) {
                $state->recordFailure($route, 'native-target-set', $operation->error_code ?? 'route.target_set_failed');
            }

            if ($operation->status === OperationStatus::Succeeded || ($result['restored'] ?? false) === true) {
                app(NativeRouteLanInput::class)->recordMigrations(ValidatedData::integerList($result['migrated_node_ids'] ?? []),
                    ValidatedData::integerList($result['migrated_node_ids'] ?? []));
            }

            return $operation;
        });
    }
}
