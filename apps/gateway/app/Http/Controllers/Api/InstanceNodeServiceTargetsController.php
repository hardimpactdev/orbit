<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\AttachInstanceNodeServiceTargetAction;
use App\Actions\Instances\DetachInstanceNodeServiceTargetAction;
use App\Data\Operations\OperationData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Requests\Instances\AttachInstanceNodeServiceTargetRequest;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Instance;
use App\Models\Node;
use App\Support\ValidatedData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresNodeAccess(ServingNode::EnvironmentInstanceOwning)]
final class InstanceNodeServiceTargetsController extends Controller
{
    public function store(AttachInstanceNodeServiceTargetRequest $request, Instance $instance, AttachInstanceNodeServiceTargetAction $action): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['data' => $action->execute($instance, ValidatedData::integer($data['process_id']),
            ValidatedData::string($data['protocol'] ?? 'tcp'), isset($data['port']) ? ValidatedData::integer($data['port']) : null,
            ValidatedData::string($data['host_env_key']), ValidatedData::string($data['port_env_key']))->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    #[StartsOperation('instance.node_service_detach')]
    public function destroy(Request $request, Instance $instance, int $target, DetachInstanceNodeServiceTargetAction $action): JsonResponse
    {
        $caller = RequireActiveWireGuardPeer::activePeer($request);
        $operation = $action->execute($instance, $target, $caller instanceof Node ? 'node:'.$caller->id : null, $request->attributes->getString('orbit.request_id'));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }
}
