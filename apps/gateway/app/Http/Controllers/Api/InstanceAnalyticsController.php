<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Analytics\RecordAnalyticsIntentAction;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresNodeAccess(ServingNode::InstanceOwning)]
final class InstanceAnalyticsController extends Controller
{
    public function show(Request $request, Instance $instance, ShowInstanceAnalyticsAction $action): JsonResponse
    {
        return $this->respond($request, $action->execute($instance));
    }

    #[StartsOperation('analytics.enable')]
    public function enable(
        EnableInstanceAnalyticsRequest $request,
        Instance $instance,
        RecordAnalyticsIntentAction $action,
    ): JsonResponse {
        $operation = $action->execute($instance, true, $request->hosts(), $request->timezone(), $request->goals());

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }

    #[StartsOperation('analytics.disable')]
    public function disable(Request $request, Instance $instance, RecordAnalyticsIntentAction $action): JsonResponse
    {
        $operation = $action->execute($instance, false);

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }

    public function stats(Request $request, Instance $instance, ShowInstanceAnalyticsStatsAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action->execute($instance),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    private function respond(Request $request, InstanceAnalyticsData $data): JsonResponse
    {
        return response()->json([
            'data' => $data->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
