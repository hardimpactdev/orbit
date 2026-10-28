<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Nodes\AddNodeAccessAction;
use App\Actions\Nodes\RemoveNodeAccessAction;
use App\Data\Operations\OperationData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class NodeAccessController extends Controller
{
    public function store(
        Request $request,
        Node $servingNode,
        Node $consumerNode,
        AddNodeAccessAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => $action->execute($consumerNode, $servingNode)->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[StartsOperation('node.access_remove')]
    public function destroy(
        Request $request,
        Node $servingNode,
        Node $consumerNode,
        RemoveNodeAccessAction $action,
    ): JsonResponse {
        $caller = $request->user();
        assert($caller instanceof Node, description: 'Authenticated peer must be a Node.');

        $result = $action->start($consumerNode, $servingNode, $caller, $request->attributes->getString('orbit.request_id'));

        return response()->json([
            'data' => $result instanceof Operation ? OperationData::fromModel($result)->toArray() : $result->toArray(),
            'meta' => $this->meta($request),
        ], $result instanceof Operation ? 202 : 200);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
