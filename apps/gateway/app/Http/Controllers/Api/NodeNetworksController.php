<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Nodes\StartNodeNetworkOperationAction;
use App\Data\Operations\OperationData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Nodes\ConvergeNodeNetworkRequest;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Node;
use Illuminate\Http\JsonResponse;

final class NodeNetworksController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Target)]
    #[StartsOperation('node.network_converge')]
    public function converge(ConvergeNodeNetworkRequest $request, Node $node, StartNodeNetworkOperationAction $action): JsonResponse
    {
        return response()->json([
            'data' => OperationData::fromModel($action->execute($node, ! $node->roles()->exists(), $request->attributes->getString('orbit.request_id')))->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ], 202);
    }
}
