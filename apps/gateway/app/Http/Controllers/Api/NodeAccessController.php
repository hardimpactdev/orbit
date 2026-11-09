<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Nodes\AddNodeAccessAction;
use App\Actions\Nodes\RemoveNodeAccessAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Route IDs stay unbound until Gateway authority has been checked. */
#[RequiresNodeAccess(ServingNode::Gateway)]
final class NodeAccessController extends Controller
{
    public function store(
        Request $request,
        string $servingNode,
        string $consumerNode,
        AddNodeAccessAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => $action->execute(
                Node::query()->findOrFail($consumerNode),
                Node::query()->findOrFail($servingNode),
            )->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    public function destroy(
        Request $request,
        string $servingNode,
        string $consumerNode,
        RemoveNodeAccessAction $action,
    ): JsonResponse {
        $caller = $request->user();
        assert($caller instanceof Node, description: 'Authenticated peer must be a Node.');

        return response()->json([
            'data' => $action->execute(
                Node::query()->findOrFail($consumerNode),
                Node::query()->findOrFail($servingNode),
                $caller,
            )->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
