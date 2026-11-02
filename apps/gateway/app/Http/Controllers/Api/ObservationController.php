<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Doctor\StartDoctorAction;
use App\Data\Operations\OperationData;
use App\Domain\Tasks\TaskExtensionState;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Observation\ObservationRequest;
use App\Http\Requests\Operations\StartsOperation;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class ObservationController extends Controller
{
    #[StartsOperation('doctor.inspect')]
    public function plan(ObservationRequest $request, string $collection, StartDoctorAction $action): JsonResponse
    {
        return $this->collect($request, $action);
    }

    #[StartsOperation('doctor.inspect')]
    public function health(ObservationRequest $request, StartDoctorAction $action): JsonResponse
    {
        return $this->collect($request, $action, true);
    }

    private function collect(ObservationRequest $request, StartDoctorAction $action, bool $horizonOnly = false): JsonResponse
    {
        if (! app(TaskExtensionState::class)->enabled()) {
            return $this->respond($request, ['status' => 'disabled']);
        }

        return $this->respond($request, OperationData::fromModel($action->collect($request->attributes->getString('orbit.request_id'), $horizonOnly))->toArray(), 202);
    }

    /** @param array<string, mixed> $data */
    private function respond(ObservationRequest $request, array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], $status);
    }
}
