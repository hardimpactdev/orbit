<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\InstanceSmokeRepairAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instances\CreateInstanceSmokeRepairRequest;
use App\Http\Requests\Instances\InstanceSmokeReceiptRequest;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Http\JsonResponse;

final class InstanceSmokeRepairController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function store(CreateInstanceSmokeRepairRequest $request, Instance $instance, InstanceSmokeRepairAction $action): JsonResponse
    {
        $caller = $request->user();
        assert($caller instanceof Node);

        return response()->json(['data' => $action->execute($instance, $caller, $request->failureKey(), $request->key())->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 201);
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function receipt(InstanceSmokeReceiptRequest $request, Instance $instance, InstanceSmokeRepairAction $action): JsonResponse
    {
        $caller = $request->user();
        assert($caller instanceof Node);

        return response()->json(['data' => $action->receipt($instance, $caller, $request->requestKey())->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }
}
