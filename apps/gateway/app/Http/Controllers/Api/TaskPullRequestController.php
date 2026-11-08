<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tasks\StartTaskPullRequestStepAction;
use App\Data\Operations\OperationData;
use App\Domain\Operations\OperationKinds;
use App\Http\Authorization\CallerName;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\StartsOperation;
use App\Http\Requests\Tasks\TaskPullRequestMergeRequest;
use App\Http\Requests\Tasks\TaskPullRequestReadyRequest;
use App\Models\Operation;
use App\Models\Task;
use Illuminate\Http\JsonResponse;

final class TaskPullRequestController extends Controller
{
    /** Takes the group's published PR out of draft and, in manual mode, requests the reviewer's review. */
    #[StartsOperation(OperationKinds::TasksPrReady, requiresKey: true, bindCaller: true)]
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function ready(TaskPullRequestReadyRequest $request, Task $task, StartTaskPullRequestStepAction $action): JsonResponse
    {
        $operation = $action->ready($task, $request->string('head_sha')->toString(), $request->string('mode')->toString(),
            CallerName::fromRequest($request), $request->attributes->getString('orbit.request_id'));

        return $this->accepted($request, $operation);
    }

    /** Squash-merges the group's published PR on the exact approved head. */
    #[StartsOperation(OperationKinds::TasksPrMerge, requiresKey: true, bindCaller: true)]
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function merge(TaskPullRequestMergeRequest $request, Task $task, StartTaskPullRequestStepAction $action): JsonResponse
    {
        $operation = $action->merge($task, $request->string('head_sha')->toString(),
            CallerName::fromRequest($request), $request->attributes->getString('orbit.request_id'));

        return $this->accepted($request, $operation);
    }

    private function accepted(TaskPullRequestReadyRequest|TaskPullRequestMergeRequest $request, Operation $operation): JsonResponse
    {
        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }
}
