<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Proof\StartProofRunAction;
use App\Data\Operations\OperationData;
use App\Domain\Operations\OperationKinds;
use App\Http\Authorization\CallerName;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\StartsOperation;
use App\Http\Requests\Proof\StartProofRunRequest;
use Illuminate\Http\JsonResponse;

final class ProofRunsController extends Controller
{
    /** Check the commit, select its cases, and start the run; a commit with nothing to prove answers a succeeded run at once. */
    #[StartsOperation(OperationKinds::ProofRun, requiresKey: true)]
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function store(StartProofRunRequest $request, StartProofRunAction $action): JsonResponse
    {
        $requestId = $request->attributes->getString('orbit.request_id');
        $operation = $action->execute($request->payload(), CallerName::fromRequest($request), $requestId === '' ? null : $requestId);

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => ['request_id' => $requestId]], 202);
    }
}
