<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Routes\AdmitClusterRouterOperationAction;
use App\Data\Operations\OperationData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clusters\ClusterDestructiveRequest;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Cluster;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClusterRouterController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Target)]
    #[StartsOperation('cluster.router_set')]
    public function set(Request $request, Cluster $cluster, Node $node, AdmitClusterRouterOperationAction $action): JsonResponse
    {
        $operation = $action->set($cluster, $node, $request->attributes->getString('orbit.request_id'));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }

    #[RequiresNodeAccess(ServingNode::ClusterOwning)]
    #[StartsOperation('cluster.router_unset')]
    public function unset(ClusterDestructiveRequest $request, Cluster $cluster, AdmitClusterRouterOperationAction $action): JsonResponse
    {
        $operation = $action->unset($cluster, $request->attributes->getString('orbit.request_id'));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }
}
