<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Nodes\ListNodesAction;
use App\Actions\Nodes\ProvisionNodeAction;
use App\Actions\Nodes\RemoveNodeAction;
use App\Actions\Nodes\RenameNodeAction;
use App\Actions\Nodes\SetNodeWireGuardKeyAction;
use App\Actions\Nodes\ShowNodeAction;
use App\Actions\Nodes\UpdateNodeSettingsAction;
use App\Data\Nodes\NodeAccessData;
use App\Data\Nodes\NodeData;
use App\Data\Operations\OperationData;
use App\Data\Projects\DevelopmentNodeExclusionData;
use App\Domain\Nodes\NodeUpdates;
use App\Domain\Projects\DevelopmentNodeExclusion;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Nodes\ProvisionNodeRequest;
use App\Http\Requests\Nodes\RemoveNodeRequest;
use App\Http\Requests\Nodes\RenameNodeRequest;
use App\Http\Requests\Nodes\SetNodeWireGuardKeyRequest;
use App\Http\Requests\Nodes\UpdateNodeSettingsRequest;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Node;
use App\Models\Operation;
use App\Models\ProjectNodeExclusion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NodesController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Collection)]
    public function index(Request $request, ListNodesAction $action, NodeUpdates $updates): JsonResponse
    {
        $consumer = $request->user();
        assert($consumer instanceof Node, description: 'Authenticated peer must be a Node.');

        $nodes = $action->handle($consumer);
        $updating = $updates->forNodes($nodes);

        return response()->json([
            'data' => $nodes
                ->map(static fn (Node $node): array => NodeData::fromModel($node, $updating)->toArray())
                ->values()
                ->all(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    #[RequiresNodeAccess(ServingNode::Target)]
    public function show(Request $request, Node $node, ShowNodeAction $action): JsonResponse
    {
        $node = $action->handle($node);

        return response()->json([
            'data' => [
                ...NodeData::fromModel($node)->toArray(),
                'access' => NodeAccessData::fromModel($node)->toArray(),
                'excluded_projects' => app(DevelopmentNodeExclusion::class)->forNode($node)
                    ->map(static fn (ProjectNodeExclusion $exclusion): array => DevelopmentNodeExclusionData::fromModel($exclusion)->toArray())
                    ->values()
                    ->all(),
            ],
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    #[RequiresNodeAccess(ServingNode::Target)]
    public function destroy(
        Request $request,
        RemoveNodeRequest $removal,
        Node $node,
        RemoveNodeAction $action,
    ): JsonResponse {
        $caller = $request->user();

        if (! $caller instanceof Node) {
            abort(403);
        }

        $request->attributes->set('orbit.target_node_snapshot', [
            'id' => $node->id,
            'name' => $node->name,
        ]);

        return response()->json([
            'data' => $action->execute($node, $caller, $removal->offline(), $removal->force())->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function store(ProvisionNodeRequest $request, ProvisionNodeAction $action): JsonResponse
    {
        $node = $action->execute($request->payload());
        $requestId = $request->attributes->get('orbit.request_id');

        return response()->json([
            'data' => NodeData::fromModel($node)->toArray(),
            'meta' => ['request_id' => is_string($requestId) ? $requestId : null],
        ], 201);
    }

    #[RequiresNodeAccess(ServingNode::Target)]
    public function rename(
        RenameNodeRequest $request,
        Node $node,
        RenameNodeAction $action,
    ): JsonResponse {
        $node = $action->execute($node, $request->name());

        return response()->json([
            'data' => NodeData::fromModel($node)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    #[RequiresNodeAccess(ServingNode::Target)]
    public function wireguardKey(
        SetNodeWireGuardKeyRequest $request,
        Node $node,
        SetNodeWireGuardKeyAction $action,
    ): JsonResponse {
        $node = $action->execute($node, $request->publicKey());

        return response()->json([
            'data' => NodeData::fromModel($node)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    #[RequiresNodeAccess(ServingNode::Target)]
    #[StartsOperation('node.settings')]
    public function settings(
        UpdateNodeSettingsRequest $request,
        Node $node,
        UpdateNodeSettingsAction $action,
    ): JsonResponse {
        $caller = $request->user();
        $result = $action->start($node, $request->payload(), $caller instanceof Node ? $caller : null,
            $request->attributes->getString('orbit.request_id'));
        if ($result instanceof Operation) {
            return response()->json([
                'data' => OperationData::fromModel($result)->toArray(),
                'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
            ], 202);
        }

        return response()->json([
            'data' => NodeData::fromModel($result)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
