<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Nodes\StartNodeConvergeAction;
use App\Data\Operations\OperationData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fleet\ConvergeNodeFootprintRequest;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Node;
use Illuminate\Http\JsonResponse;

final class NodeFootprintsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Target)]
    #[StartsOperation('node.converge', secretInputs: ['worker_credentials'])]
    public function converge(ConvergeNodeFootprintRequest $request, Node $node, StartNodeConvergeAction $action): JsonResponse
    {
        return response()->json([
            'data' => OperationData::fromModel($action->execute($node, $request->force(), requestId: $request->attributes->getString('orbit.request_id'), workerCredentials: $request->workerCredentials()))->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ], 202);
    }
}
