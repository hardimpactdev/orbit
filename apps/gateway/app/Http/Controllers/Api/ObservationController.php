<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Doctor\StartDoctorAction;
use App\Actions\Observation\MaintainObservationStateAction;
use App\Data\Operations\OperationData;
use App\Domain\Tasks\TaskExtensionState;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Observation\MaintainObservationRequest;
use App\Http\Requests\Observation\ObservationRequest;
use App\Support\ValidatedData;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class ObservationController extends Controller
{
    public function plan(ObservationRequest $request, string $collection, StartDoctorAction $action, TaskExtensionState $tasks): JsonResponse
    {
        if (! $tasks->enabled()) {
            return $this->respond($request, ['status' => 'disabled']);
        }

        return $this->respond($request, OperationData::fromModel($action->collect($request->attributes->getString('orbit.request_id')))->toArray());
    }

    public function maintain(MaintainObservationRequest $request, string $routine, MaintainObservationStateAction $action): JsonResponse
    {
        return $this->respond($request, $action->execute($routine, ValidatedData::string($request->validated('run_id'))));
    }

    /** @param array<string, mixed> $data */
    private function respond(ObservationRequest $request, array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }
}
