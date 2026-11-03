<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Doctor\RunDoctorAction;
use App\Domain\Tasks\TaskExtensionState;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Observation\ObservationRequest;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class ObservationController extends Controller
{
    public function plan(ObservationRequest $request, string $collection, RunDoctorAction $action): JsonResponse
    {
        return $this->collect($request, $action);
    }

    public function health(ObservationRequest $request, RunDoctorAction $action): JsonResponse
    {
        return $this->collect($request, $action, true);
    }

    private function collect(ObservationRequest $request, RunDoctorAction $action, bool $horizonOnly = false): JsonResponse
    {
        if (! app(TaskExtensionState::class)->enabled()) {
            return $this->respond($request, ['status' => 'disabled']);
        }

        return $this->respond($request, $action->collect($horizonOnly)->toArray());
    }

    /** @param array<string, mixed> $data */
    private function respond(ObservationRequest $request, array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], $status);
    }
}
