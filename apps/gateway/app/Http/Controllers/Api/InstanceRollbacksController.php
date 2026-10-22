<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\StartInstanceRollbackAction;
use App\Data\Operations\OperationData;
use App\Http\Authorization\CallerName;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instances\RollbackInstanceRequest;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Instance;
use Illuminate\Http\JsonResponse;

final class InstanceRollbacksController extends Controller
{
    /** Starts an `instance.rollback` operation and answers 202 with it; the rollback runs on the Gateway queue. */
    #[StartsOperation('instance.rollback')]
    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function store(
        RollbackInstanceRequest $request,
        Instance $instance,
        StartInstanceRollbackAction $action,
    ): JsonResponse {
        $requestId = $request->attributes->getString('orbit.request_id');
        $operation = $action->execute($instance, $request->release(), CallerName::fromRequest($request), $requestId === '' ? null : $requestId);

        return response()->json([
            'data' => OperationData::fromModel($operation)->toArray(),
            'meta' => ['request_id' => $requestId],
        ], 202);
    }
}
