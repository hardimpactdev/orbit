<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Nodes\AdmitNodeBaseInventoryAction;
use App\Data\Operations\OperationData;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NodeBaseInventoryController
{
    #[RequiresNodeAccess(ServingNode::Target)]
    #[StartsOperation('node.base_inventory', bindCaller: true)]
    public function store(Request $request, Node $node, AdmitNodeBaseInventoryAction $action): JsonResponse
    {
        if ($request->getContent() !== '' || $request->query->count() !== 0) {
            throw new ResourceOperationException('validation.failed', 'Base inventory accepts only the Node ID in the URL.', 422);
        }

        $requestId = $request->attributes->getString('orbit.request_id');

        return response()->json([
            'data' => OperationData::summary($action->execute($node, $requestId))->toArray(),
            'meta' => ['request_id' => $requestId],
        ], 202);
    }
}
