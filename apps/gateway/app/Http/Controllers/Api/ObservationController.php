<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Observation\MaintainObservationStateAction;
use App\Domain\Doctor\DoctorCollectionReads;
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
    public function plan(ObservationRequest $request, string $collection, DoctorCollectionReads $reads): JsonResponse
    {
        return $this->respond($request, $reads->plan($collection));
    }

    public function observe(ObservationRequest $request, string $collection, int $node, DoctorCollectionReads $reads): JsonResponse
    {
        return $this->respond($request, $reads->observe($collection, $node));
    }

    public function inspect(ObservationRequest $request, string $collection, int $probe, DoctorCollectionReads $reads): JsonResponse
    {
        return $this->respond($request, $reads->inspect($collection, $probe));
    }

    public function signals(ObservationRequest $request, string $collection, DoctorCollectionReads $reads): JsonResponse
    {
        return $this->respond($request, $reads->signals($collection));
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
