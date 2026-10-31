<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\ListOperationsAction;
use App\Actions\Operations\ReadOperationAction;
use App\Data\Operations\OperationData;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\EmptyOperationRequest;
use App\Http\Requests\Operations\ListOperationsRequest;
use App\Http\Requests\Operations\ShowOperationRequest;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reads and cancels operations without waiting for native execution.
 */
final class OperationsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Collection)]
    public function index(ListOperationsRequest $request, ListOperationsAction $action): JsonResponse
    {
        $consumer = $request->user();

        if (! $consumer instanceof Node) {
            throw new ResourceOperationException('peer.identity_unknown', 'Active WireGuard peer identity required.', 403);
        }

        return $this->respond($request, $action->execute($consumer, $request->filters())
            ->map(static fn (Operation $operation): array => OperationData::fromModel($operation, $operation->caller_node_name)->toArray())
            ->values()
            ->all());
    }

    #[RequiresNodeAccess(ServingNode::OperationOwning)]
    public function show(ShowOperationRequest $request, Operation $operation, ReadOperationAction $read): JsonResponse
    {
        return $this->respond($request, OperationData::fromModel($read->execute($operation), $operation->caller_node_name)->toArray());
    }

    #[RequiresNodeAccess(ServingNode::OperationOwning)]
    public function cancel(EmptyOperationRequest $request, Operation $operation, CancelOperationAction $action): JsonResponse
    {
        $callerNodeName = $operation->caller_node_name;

        return $this->respond($request, OperationData::fromModel($action->execute($operation), $callerNodeName)->toArray());
    }

    /** @param array<int|string, mixed> $data */
    private function respond(Request $request, array $data): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
