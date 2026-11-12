<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\AttachInstanceNodeServiceTargetAction;
use App\Actions\Instances\DetachInstanceNodeServiceTargetAction;
use App\Data\Instances\InstanceNodeServiceTargetRecordData;
use App\Data\Operations\OperationData;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Requests\Instances\AttachInstanceNodeServiceTargetRequest;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Support\ValidatedData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresNodeAccess(ServingNode::EnvironmentInstanceOwning)]
final class InstanceNodeServiceTargetsController extends Controller
{
    public function index(Request $request, Instance $instance): JsonResponse
    {
        return response()->json(['data' => $instance->nodeServiceTargets()->orderBy('id')->get()
            ->map(InstanceNodeServiceTargetRecordData::fromModel(...))->all(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    public function store(AttachInstanceNodeServiceTargetRequest $request, Instance $instance, AttachInstanceNodeServiceTargetAction $action): JsonResponse
    {
        $data = $request->validated();
        $process = Process::query()->find(ValidatedData::integer($data['process_id']));
        if ($process?->owner_type === Node::class) {
            $serving = Node::query()->findOrFail($process->owner_id);
            $caller = $request->user();
            if (! $caller instanceof Node || ! app(NodeAccessAuthorizer::class)->allows($caller, $serving)) {
                throw new ResourceOperationException('node_access.required', 'Node access is required.', 403,
                    details: ['serving_node' => ['id' => $serving->id, 'name' => $serving->name]]);
            }
        }

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
