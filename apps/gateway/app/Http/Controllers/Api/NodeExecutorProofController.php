<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Data\Operations\OperationData;
use App\Domain\Executor\ExecutorProofOperation;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Nodes\NodeExecutorProofRequest;
use App\Models\Node;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::Target)]
final class NodeExecutorProofController extends Controller
{
    public function store(NodeExecutorProofRequest $request, Node $node, ExecutorProofOperation $proof): JsonResponse
    {
        $requestId = $request->attributes->getString('orbit.request_id');

        return response()->json([
            'data' => OperationData::summary($proof->diagnose($node, $requestId))->toArray(),
            'meta' => ['request_id' => $requestId],
        ]);
    }
}
