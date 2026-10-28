<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Analytics\DisableInstanceAnalyticsAction;
use App\Actions\Analytics\EnableInstanceAnalyticsAction;
use App\Actions\Analytics\ShowInstanceAnalyticsAction;
use App\Actions\Analytics\ShowInstanceAnalyticsStatsAction;
use App\Data\Analytics\InstanceAnalyticsData;
use App\Data\Operations\OperationData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Analytics\EnableInstanceAnalyticsRequest;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Instance;
use App\Models\Operation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresNodeAccess(ServingNode::InstanceOwning)]
final class InstanceAnalyticsController extends Controller
{
    public function show(Request $request, Instance $instance, ShowInstanceAnalyticsAction $action): JsonResponse
    {
        return $this->respond($request, $action->execute($instance));
    }

    #[StartsOperation('analytics.instance_enable')]
    public function enable(
        EnableInstanceAnalyticsRequest $request,
        Instance $instance,
        EnableInstanceAnalyticsAction $action,
    ): JsonResponse {
        return $this->operation($request, $action->execute($instance, $request->hosts()));
    }

    #[StartsOperation('analytics.instance_disable')]
    public function disable(Request $request, Instance $instance, DisableInstanceAnalyticsAction $action): JsonResponse
    {
        return $this->operation($request, $action->execute($instance));
    }

    public function stats(Request $request, Instance $instance, ShowInstanceAnalyticsStatsAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action->execute($instance),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    private function operation(Request $request, Operation $operation): JsonResponse
    {
        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }

    private function respond(Request $request, InstanceAnalyticsData $data): JsonResponse
    {
        return response()->json([
            'data' => $data->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
