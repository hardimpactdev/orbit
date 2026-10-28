<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\ReadInstanceSmokeContextAction;
use App\Actions\Instances\ReadInstanceSmokeReceiptAction;
use App\Actions\Instances\StartInstanceSmokeAction;
use App\Data\Operations\OperationData;
use App\Domain\Operations\OperationKinds;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instances\InstanceSmokeReceiptRequest;
use App\Http\Requests\Instances\StartInstanceSmokeRequest;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InstanceSmokeController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function context(Request $request, Instance $instance, ReadInstanceSmokeContextAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute($instance)->toArray(), 'meta' => ['request_id' => $request
            ->attributes->getString('orbit.request_id')]]);
    }

    #[StartsOperation(OperationKinds::InstanceSmoke, requiresKey: true, bindCaller: true)]
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function store(StartInstanceSmokeRequest $request, Instance $instance, StartInstanceSmokeAction $action): JsonResponse
    {
        $caller = $request->user();
        assert($caller instanceof Node);
        $operation = $action->execute($instance, $caller, $request->payload(), $request->attributes->getString('orbit.request_id'));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function receipt(InstanceSmokeReceiptRequest $request, Instance $instance,
        ReadInstanceSmokeReceiptAction $action): JsonResponse
    {
        $caller = $request->user();
        assert($caller instanceof Node);

        return response()->json(['data' => $action->execute($instance, $caller, $request->requestKey())->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }
}
