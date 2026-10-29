<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Fleet\AdmitFleetRolloutNodeAction;
use App\Actions\Fleet\AdmitRecipePrAction;
use App\Actions\Fleet\FleetRolloutPlanAction;
use App\Actions\Fleet\PeerDnsTargetsAction;
use App\Actions\Fleet\RecipeFleetPlanAction;
use App\Actions\Fleet\RecipePrPlanAction;
use App\Actions\Fleet\RecordRecipeFleetStateAction;
use App\Data\Operations\OperationData;
use App\Domain\Fleet\FleetWindow;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fleet\AdmitRolloutNodeRequest;
use App\Http\Requests\Fleet\FleetMaintenanceRequest;
use App\Http\Requests\Fleet\RecipeFleetStateRequest;
use App\Http\Requests\Fleet\RecipePrRequest;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Node;
use App\Support\ValidatedData;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class FleetMaintenanceController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Target)]
    public function visitPolicy(FleetMaintenanceRequest $request, Node $node, FleetWindow $window): JsonResponse
    {
        $node->load('roles');

        return response()->json(['data' => ['daytime' => $window->daytime($node), 'window_open' => $window->allows($node)]]);
    }

    public function rolloutPlan(FleetMaintenanceRequest $request, FleetRolloutPlanAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    #[RequiresNodeAccess(ServingNode::Target)]
    #[StartsOperation('fleet.rollout')]
    public function rolloutNode(AdmitRolloutNodeRequest $request, Node $node, AdmitFleetRolloutNodeAction $action): JsonResponse
    {
        $operation = $action->execute($node, ValidatedData::integer($request->validated('rollout_id')));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }

    public function recipeState(RecipeFleetStateRequest $request, RecordRecipeFleetStateAction $action): JsonResponse
    {
        $action->execute(ValidatedData::integer($request->validated('build_id')), ValidatedData::string($request->validated('state')));

        return response()->json(['data' => ['recorded' => true], 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    public function recipePlan(FleetMaintenanceRequest $request, RecipeFleetPlanAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    public function recipePrPlan(FleetMaintenanceRequest $request, RecipePrPlanAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    #[StartsOperation('fleet.recipe_pr')]
    public function recipePr(RecipePrRequest $request, AdmitRecipePrAction $action): JsonResponse
    {
        $operation = $action->execute(ValidatedData::string($request->validated('action')), ValidatedData::string($request->validated('variant')),
            ValidatedData::integer($request->validated('pull_number')), (ValidatedData::nullableString($request->validated('head_sha')) ?? ''), ValidatedData::string($request->validated('date')));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }

    public function peerDnsTargets(FleetMaintenanceRequest $request, PeerDnsTargetsAction $action): JsonResponse
    {
        return response()->json(['data' => $action->execute(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }
}
