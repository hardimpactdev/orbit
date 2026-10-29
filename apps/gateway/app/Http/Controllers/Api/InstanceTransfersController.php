<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\StartInstanceTransferAction;
use App\Data\Operations\OperationData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Requests\Instances\TransferInstanceRequest;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Http\JsonResponse;

final class InstanceTransfersController extends Controller
{
    #[RequiresNodeAccess(ServingNode::InstanceTransfer)]
    #[StartsOperation('instance.transfer')]
    public function store(
        TransferInstanceRequest $request,
        Instance $instance,
        StartInstanceTransferAction $action,
    ): JsonResponse {
        $caller = RequireActiveWireGuardPeer::activePeer($request);
        $operation = $action->execute($instance, $request->payload(), $caller instanceof Node ? 'node:'.$caller->id : null, $request->attributes->getString('orbit.request_id'));

        return response()->json([
            'data' => OperationData::fromModel($operation)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ], 202);
    }
}
