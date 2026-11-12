<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Data\Operations\OperationData;
use App\Domain\InstanceRuntime\InstanceRuntimeUpdate;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InstanceRuntimeController extends Controller
{
    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    #[StartsOperation('instance.runtime_update')]
    public function store(Request $request, Instance $instance, InstanceRuntimeUpdate $action): JsonResponse
    {
        $caller = RequireActiveWireGuardPeer::activePeer($request);
        $operation = $action->start($instance, $caller instanceof Node ? 'node:'.$caller->id : null, $request->attributes->getString('orbit.request_id'));

        return response()->json([
            'data' => OperationData::fromModel($operation)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ], 202);
    }
}
