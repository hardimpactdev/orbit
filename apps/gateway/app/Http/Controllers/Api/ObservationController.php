<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Doctor\StartDoctorAction;
use App\Actions\Observation\StartObservationMaintenance;
use App\Data\Operations\OperationData;
use App\Domain\Tasks\TaskExtensionState;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Observation\ObservationRequest;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class ObservationController extends Controller
{
    public function plan(ObservationRequest $request, string $collection, StartDoctorAction $action, TaskExtensionState $tasks): JsonResponse
    {
        if (! $tasks->enabled()) {
            return $this->respond($request, ['status' => 'disabled']);
        }

        return $this->respond($request, OperationData::fromModel($action->collect($request->attributes->getString('orbit.request_id')))->toArray(), 202);
    }

    public function maintain(ObservationRequest $request, string $routine, StartObservationMaintenance $action): JsonResponse
    {
        if ($routine === 'health' && ! app(TaskExtensionState::class)->enabled()) {
            return $this->respond($request, ['status' => 'disabled']);
        }
        $operation = $routine === 'health'
            ? app(StartDoctorAction::class)->collect($request->attributes->getString('orbit.request_id'), horizonOnly: true)
            : $action->execute($routine, $request->attributes->getString('orbit.request_id'));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }

    /** @param array<string, mixed> $data */
    private function respond(ObservationRequest $request, array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], $status);
    }
}
