<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\StartInstancePublishAction;
use App\Data\Operations\OperationData;
use App\Http\Authorization\CallerName;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instances\PublishInstanceRequest;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Instance;
use Illuminate\Http\JsonResponse;

final class InstancePublishController extends Controller
{
    #[StartsOperation('instance.publish')]
    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function store(PublishInstanceRequest $request, Instance $instance, StartInstancePublishAction $action): JsonResponse
    {
        $requestId = $request->attributes->getString('orbit.request_id');
        $operation = $action->execute($instance, $request->inputData(), CallerName::fromRequest($request), $requestId === '' ? null : $requestId);

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => ['request_id' => $requestId]], 202);
    }
}
