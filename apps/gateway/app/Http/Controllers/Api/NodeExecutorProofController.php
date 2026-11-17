<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Data\Operations\OperationData;
use App\Domain\Executor\ExecutorProofOperation;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

#[RequiresNodeAccess(ServingNode::Target)]
final class NodeExecutorProofController extends Controller
{
    public function store(Request $request, Node $node, NodeAccessAuthorizer $access, ExecutorProofOperation $proof): JsonResponse
    {
        $caller = $request->user();
        if (! $caller instanceof Node || ! $access->hasGatewayAuthority($caller)) {
            throw new ResourceOperationException('node_access.required', 'Gateway access is required.', 403);
        }

        if ($request->all() !== []) {
            throw ValidationException::withMessages(['input' => ['The executor diagnostic accepts no inputs.']]);
        }

        $requestId = $request->attributes->getString('orbit.request_id');

        return response()->json([
            'data' => OperationData::summary($proof->diagnose($node, $requestId))->toArray(),
            'meta' => ['request_id' => $requestId],
        ]);
    }
}
