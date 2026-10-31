<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Nodes\RecordNodeSshHostKeyAction;
use App\Data\Nodes\NodeData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Nodes\RecordNodeSshHostKeyRequest;
use App\Models\Node;
use Illuminate\Http\JsonResponse;

final class NodeSshHostKeysController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Target)]
    public function store(RecordNodeSshHostKeyRequest $request, Node $node, RecordNodeSshHostKeyAction $action): JsonResponse
    {
        return response()->json([
            'data' => NodeData::fromModel($action->execute($node, $request->observedHost(), $request->hostKey()))->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
