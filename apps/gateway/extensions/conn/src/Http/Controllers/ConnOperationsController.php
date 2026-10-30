<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Controllers;

use App\Actions\Operations\CancelOperationAction;
use App\Http\Controllers\Api\OperationsController;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireActiveWireGuardPeer;
use App\Http\Requests\Operations\EmptyOperationRequest;
use App\Http\Requests\Operations\ShowOperationRequest;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Conn callers can follow their own effects without access to the Gateway Node. */
final class ConnOperationsController extends Controller
{
    public function show(ShowOperationRequest $request, Operation $operation): JsonResponse
    {
        $this->assertOwner($request, $operation);

        $data = OperationsController::withSteps($operation, $request->afterSequence(), $operation->caller_node_name);

        return response()->json(['data' => $data,
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    public function cancel(EmptyOperationRequest $request, Operation $operation, CancelOperationAction $cancel): JsonResponse
    {
        $this->assertOwner($request, $operation);

        return response()->json(['data' => OperationsController::withSteps($cancel->execute($operation), null, $operation->caller_node_name),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    private function assertOwner(Request $request, Operation $operation): void
    {
        $caller = RequireActiveWireGuardPeer::activePeer($request);
        $target = match ($operation->kind) {
            'conn.pairing_create' => 'conn_pairing', 'conn.pairing_revoke' => 'conn_pairing_node', 'conn.gateway_sessions_revoke' => 'conn_environment', default => null,
        };
        abort_unless($caller instanceof Node && $operation->callerNodeId() === $caller->id
            && $target !== null && $operation->target_type === $target, 404);
    }
}
