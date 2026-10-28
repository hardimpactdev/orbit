<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Fleet\AdmitPeerDnsAction;
use App\Data\Operations\OperationData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresNodeAccess(ServingNode::Target)]
final class FleetPeerDnsController extends Controller
{
    #[StartsOperation('fleet.peer_dns')]
    public function store(Request $request, Node $node, AdmitPeerDnsAction $action): JsonResponse
    {
        $caller = $request->getUserResolver()();
        $operation = $action->execute($node, $caller instanceof Node ? $caller->name : null, $request->attributes->getString('orbit.request_id'));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }
}
