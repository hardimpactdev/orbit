<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tasks\ShowTaskGroupEgressAction;
use App\Data\Tasks\TaskGroupEgressData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskGroupEgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The outside hosts of a task group's VM ([Task VM egress log](/reference/task-vm-egress-log)). */
#[RequiresNodeAccess(ServingNode::Gateway)]
final class TaskEgressController extends Controller
{
    public function show(Request $request, Task $task, ShowTaskGroupEgressAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action->execute($task)
                ->map(static fn (TaskGroupEgress $row): array => TaskGroupEgressData::fromModel($row)->toArray())
                ->values()
                ->all(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
