<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Fleet\AdmitFleetCliAction;
use App\Actions\Fleet\AdmitFleetRolloutAction;
use App\Actions\Fleet\FleetRolloutPlanAction;
use App\Actions\Fleet\PeerDnsTargetsAction;
use App\Data\Operations\OperationData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fleet\AdmitFleetReleaseRequest;
use App\Http\Requests\Fleet\FleetMaintenanceRequest;
use App\Http\Requests\Operations\StartsOperation;
use App\Support\ValidatedData;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class FleetMaintenanceController extends Controller
{
    public function rolloutPlan(FleetMaintenanceRequest $request, FleetRolloutPlanAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    #[StartsOperation('fleet.rollout')]
    public function rollout(AdmitFleetReleaseRequest $request, AdmitFleetRolloutAction $action): JsonResponse
    {
        $operation = $action->execute($request->validated('node_ids') === null ? null : ValidatedData::integerList($request->validated('node_ids')));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }

    #[StartsOperation('fleet.cli_converge')]
    public function cli(AdmitFleetReleaseRequest $request, AdmitFleetCliAction $action): JsonResponse
    {
        $operation = $action->fleet($request->validated('node_ids') === null ? null : ValidatedData::integerList($request->validated('node_ids')));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }

    public function peerDnsTargets(FleetMaintenanceRequest $request, PeerDnsTargetsAction $action): JsonResponse
    {
        return response()->json(['data' => array_column($action->execute(), 'node_id'),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    public function peerDnsPlan(FleetMaintenanceRequest $request, PeerDnsTargetsAction $action): JsonResponse
    {
        // One peer at a time: the first changed peer stays first until its run succeeds, so later peers wait.
        return response()->json(['data' => array_slice($action->execute(), 0, 1),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }
}
