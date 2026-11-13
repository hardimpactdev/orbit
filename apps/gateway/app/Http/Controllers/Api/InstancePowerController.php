<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Data\Operations\OperationData;
use App\Domain\Instances\Power\InstancePower;
use App\Domain\Instances\Power\InstanceSleep;
use App\Domain\Instances\Power\InstanceWake;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InstancePowerController extends Controller
{
    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    #[StartsOperation('instance.sleep')]
    public function sleep(Request $request, Instance $instance, InstanceSleep $action): JsonResponse
    {
        return $this->started($request, $instance, $action);
    }

    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    #[StartsOperation('instance.wake')]
    public function wake(Request $request, Instance $instance, InstanceWake $action): JsonResponse
    {
        return $this->started($request, $instance, $action);
    }

    private function started(Request $request, Instance $instance, InstancePower $action): JsonResponse
    {
        $caller = RequireActiveWireGuardPeer::activePeer($request);
        $operation = $action->start($instance, $caller instanceof Node ? 'node:'.$caller->id : null, $request->attributes->getString('orbit.request_id'));

        return response()->json([
            'data' => OperationData::fromModel($operation)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ], 202);
    }
}
