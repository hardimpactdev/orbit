<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\PublishAutomaticDeployPolicyAction;
use App\Actions\Instances\StartAutomaticDeployEventAction;
use App\Data\Instances\InstanceData;
use App\Data\Operations\OperationData;
use App\Domain\Operations\OperationKinds;
use App\Http\Authorization\CallerName;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instances\PublishAutomaticDeployPolicyRequest;
use App\Http\Requests\Instances\StoreAutomaticDeployEventRequest;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class InstanceAutomaticDeployPolicyController extends Controller
{
    public function update(PublishAutomaticDeployPolicyRequest $request, Instance $instance, PublishAutomaticDeployPolicyAction $action): JsonResponse
    {
        return response()->json(['data' => InstanceData::fromModel($action->execute($instance, $request->payload()))->toArray(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    #[StartsOperation(OperationKinds::InstancePolicyEvent, requiresKey: true, bindCaller: true)]
    public function store(StoreAutomaticDeployEventRequest $request, Instance $instance, StartAutomaticDeployEventAction $action): JsonResponse
    {
        $caller = $request->user();
        assert($caller instanceof Node);
        $id = $request->attributes->getString('orbit.request_id');

        return response()->json(['data' => OperationData::fromModel($action->execute($instance, $caller, $request->payload(), $id), CallerName::fromRequest($request))->toArray(), 'meta' => ['request_id' => $id]], 202);
    }
}
