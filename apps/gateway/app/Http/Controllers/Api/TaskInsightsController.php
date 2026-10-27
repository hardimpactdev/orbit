<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tasks\ShowTaskInsightsAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\ShowTaskInsightsRequest;
use Illuminate\Http\JsonResponse;

/** Task delivery numbers for the web task board ([Task insights](/reference/tasks#task-insights)). */
final class TaskInsightsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Collection)]
    public function show(ShowTaskInsightsRequest $request, ShowTaskInsightsAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action->execute($request->days(), $request->projectId())->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
