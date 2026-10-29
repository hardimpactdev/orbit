<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\StartInstanceCloneAction;
use App\Data\Operations\OperationData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Requests\Instances\CloneInstanceRequest;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Http\JsonResponse;

final class InstanceClonesController extends Controller
{
    #[RequiresNodeAccess(ServingNode::CandidateClone)]
    #[StartsOperation('instance.clone')]
    public function store(
        CloneInstanceRequest $request,
        Instance $candidate,
        StartInstanceCloneAction $action,
    ): JsonResponse {
        $caller = RequireActiveWireGuardPeer::activePeer($request);
        $operation = $action->execute($candidate, $request->payload(), $caller instanceof Node ? 'node:'.$caller->id : null, $request->attributes->getString('orbit.request_id'));
        $request->attributes->set('orbit.instance_clone', Instance::query()->findOrFail($operation->target_id));

        return response()->json([
            'data' => OperationData::fromModel($operation)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ], 202);
    }
}
