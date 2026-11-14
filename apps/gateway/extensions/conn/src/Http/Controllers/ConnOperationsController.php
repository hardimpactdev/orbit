<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Controllers;

use App\Actions\Operations\CancelOperationAction;
use App\Data\Operations\OperationData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Requests\Operations\EmptyOperationRequest;
use App\Http\Requests\Operations\ShowOperationRequest;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Conn callers can follow their own effects without access to the Gateway Node. */
#[RequiresNodeAccess(ServingNode::Collection)]
final class ConnOperationsController extends Controller
{
    public function show(ShowOperationRequest $request, Operation $operation): JsonResponse
    {
        $this->assertOwner($request, $operation);

        $data = OperationData::summary($operation, $operation->caller_node_name)->toArray();

        return response()->json(['data' => $data,
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    public function cancel(EmptyOperationRequest $request, Operation $operation, CancelOperationAction $cancel): JsonResponse
    {
        // Instance lifecycle Operations are followed here; cancellation uses the core Instance tools. Conn offers no Cancel for a clone's
        // completion: a removal cancels it (docs/reference/conn.md#remove-a-clone-whose-completion-runs).
        abort_if(in_array($operation->kind, ['instance.create', 'instance.complete', 'instance.destroy'], true), 404);
        $this->assertOwner($request, $operation);

        return response()->json(['data' => OperationData::fromModel($cancel->execute($operation), $operation->caller_node_name)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    private function assertOwner(Request $request, Operation $operation): void
    {
        $caller = RequireActiveWireGuardPeer::activePeer($request);
        $target = match ($operation->kind) {
            'conn.pairing_create' => 'conn_pairing', 'conn.pairing_revoke' => 'conn_pairing_node', 'conn.gateway_sessions_revoke' => 'conn_environment', 'conn.environment_renew' => 'conn_environment',
            'instance.create', 'instance.complete' => 'instance',
            'instance.destroy' => ($operation->state['conn_destroy_caller_node_id'] ?? null) === $caller?->id ? 'instance' : null,
            default => null,
        };
        // Instance lifecycle admission records its caller as node:<id>. A clone's completion is read by the Node that started it (Conn's
        // Retry) and by the Node that created the clone, whose create admitted the first completion: the create's reader follows it.
        $node = $caller instanceof Node ? 'node:'.$caller->id : null;
        $owner = match ($operation->kind) {
            'instance.create', 'instance.destroy' => $node !== null && $operation->triggered_by === $node,
            'instance.complete' => $node !== null && ($operation->triggered_by === $node || Operation::query()->where('kind', 'instance.create')
                ->where('target_type', 'instance')->where('target_id', $operation->target_id)->where('triggered_by', $node)->exists()),
            default => $caller instanceof Node && $operation->callerNodeId() === $caller->id,
        };
        abort_unless($owner && $target !== null && $operation->target_type === $target, 404);
    }
}
