<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\InstanceSmokeControlAction;
use App\Actions\Instances\InstanceSmokeRecoveryAction;
use App\Data\Operations\OperationData;
use App\Domain\Operations\OperationKinds;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instances\ControlInstanceSmokeRequest;
use App\Http\Requests\Instances\InstanceSmokeReceiptRequest;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Http\JsonResponse;

final class InstanceSmokeControlController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function store(ControlInstanceSmokeRequest $request, Instance $instance, InstanceSmokeControlAction $action): JsonResponse
    {
        $caller = $request->user();
        assert($caller instanceof Node);

        return response()->json(['data' => $action->execute($instance, $caller, $request->payload(), $request->key())->toArray()]);
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function receipt(InstanceSmokeReceiptRequest $request, Instance $instance, InstanceSmokeControlAction $action): JsonResponse
    {
        $caller = $request->user();
        assert($caller instanceof Node);

        return response()->json(['data' => $action->receipt($instance, $caller, $request->requestKey())]);
    }

    #[StartsOperation(OperationKinds::InstanceDeploy, requiresKey: true, maxJsonBytes: 4096, bindCaller: true)]
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function redeploy(ControlInstanceSmokeRequest $request, Instance $instance, InstanceSmokeControlAction $action): JsonResponse
    {
        $caller = $request->user();
        assert($caller instanceof Node);

        return response()->json(['data' => OperationData::fromModel($action->redeploy($instance, $caller, $request->payload(), $request->attributes->getString('orbit.request_id')))->toArray()], 202);
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function redeployReceipt(InstanceSmokeReceiptRequest $request, Instance $instance, InstanceSmokeRecoveryAction $action): JsonResponse
    {
        $caller = $request->user();
        assert($caller instanceof Node);

        return response()->json(['data' => $action->rollbackReceipt($instance, $caller, $request->requestKey(), true)->toArray()]);
    }
}
