<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\Node;
use App\Services\Nodes\GatewayNodeCreator;
use App\Services\Nodes\NodeBootstrapArguments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresPermission('node:new', servingNode: ServingNode::Gateway)]
final readonly class NodeBootstrapResumeController
{
    public function __invoke(
        Request $request,
        GatewayNodeCreator $nodes,
        NodeBootstrapArguments $arguments,
    ): JsonResponse {
        /** @var mixed $resolvedUser */
        $resolvedUser = $request->user();
        $caller = $resolvedUser instanceof Node ? $resolvedUser : null;

        if (! $caller instanceof Node) {
            return response()->json([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'This caller cannot resume node bootstrap.',
                    'meta' => [],
                ],
            ], 403);
        }

        $result = $nodes->resumeBootstrap($arguments->fromRequest($request), $caller);

        return response()->json($result->payload, $result->status());
    }
}
