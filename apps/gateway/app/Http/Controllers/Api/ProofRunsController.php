<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Proof\StartProofRunAction;
use App\Actions\Proof\StartProofVerifyAction;
use App\Data\Operations\OperationData;
use App\Domain\Operations\OperationKinds;
use App\Http\Authorization\CallerName;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\StartsOperation;
use App\Http\Requests\Proof\StartProofRunRequest;
use App\Http\Requests\Proof\StartProofVerifyRequest;
use App\Models\Node;
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

    /** Prove one command on the task VM that calls, with its own nested fleet and no new VM; the caller's Node names the VM. */
    #[StartsOperation(OperationKinds::ProofVerify, requiresKey: true, bindCaller: true)]
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function verify(StartProofVerifyRequest $request, StartProofVerifyAction $action): JsonResponse
    {
        $caller = $request->user();
        assert($caller instanceof Node, description: 'Authenticated peer must be a Node.');
        $requestId = $request->attributes->getString('orbit.request_id');
        $operation = $action->execute($caller, $request->payload(), CallerName::fromRequest($request), $requestId === '' ? null : $requestId);

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => ['request_id' => $requestId]], 202);
    }
}
