<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Controllers;

use App\Data\Operations\OperationData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Orbit\Extensions\Conn\Actions\DestroyConnInstanceAction;
use Orbit\Extensions\Conn\Http\Requests\DestroyConnInstanceRequest;

final class ConnInstancesController extends Controller
{
    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    #[StartsOperation('instance.destroy', requiresKey: true, bindCaller: true)]
    public function destroy(DestroyConnInstanceRequest $request, int $instance_id, DestroyConnInstanceAction $action): JsonResponse
    {
        $caller = $request->user();
        assert($caller instanceof Node);
        $operation = $action->execute(Instance::query()->findOrFail($instance_id), $request->acknowledgeUnsaved(),
            $caller, $request->attributes->getString('orbit.request_id'));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }
}
