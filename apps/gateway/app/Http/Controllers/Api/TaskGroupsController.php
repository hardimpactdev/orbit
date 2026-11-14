<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tasks\CancelRunningSubtaskAction;
use App\Actions\Tasks\CancelTaskCheckAction;
use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Actions\Tasks\CreateTaskAction;
use App\Actions\Tasks\CreateTaskGroupAction;
use App\Actions\Tasks\DestroyTaskAction;
use App\Actions\Tasks\ListTaskGroupsAction;
use App\Actions\Tasks\ShowTaskGroupAction;
use App\Actions\Tasks\StartTaskCheckAction;
use App\Actions\Tasks\StartTaskPackageAction;
use App\Actions\Tasks\StartTaskPrepAction;
use App\Actions\Tasks\StartTaskPrepFixAction;
use App\Actions\Tasks\StartTaskProvisionAction;
use App\Actions\Tasks\StartTaskPublishAction;
use App\Actions\Tasks\StartTaskScriptAction;
use App\Actions\Tasks\StartTaskSyncAction;
use App\Actions\Tasks\StartTaskTurnAction;
use App\Actions\Tasks\StoreTaskCommentAction;
use App\Actions\Tasks\StoreTaskGroupCommentAction;
use App\Actions\Tasks\UpdateTaskAction;
use App\Actions\Tasks\UpdateTaskGroupAction;
use App\Data\Operations\OperationData;
use App\Data\Tasks\StoreTaskGroupCommentData;
use App\Data\Tasks\TaskCheckData;
use App\Data\Tasks\TaskCommentData;
use App\Data\Tasks\TaskData;
use App\Data\Tasks\TaskGroupData;
use App\Domain\Operations\OperationKinds;
use App\Domain\Tasks\TaskCancelIntent;
use App\Domain\Tasks\TaskGroupActivity;
use App\Http\Authorization\CallerName;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\StartsOperation;
use App\Http\Requests\Tasks\CreateTaskGroupRequest;
use App\Http\Requests\Tasks\CancelTaskRequest;
use App\Http\Requests\Tasks\EmptyTasksRequest;
use App\Http\Requests\Tasks\ListTaskCommentsRequest;
use App\Http\Requests\Tasks\ListTaskGroupsRequest;
use App\Http\Requests\Tasks\ShowTaskGroupRequest;
use App\Http\Requests\Tasks\StartTaskCheckRequest;
use App\Http\Requests\Tasks\StartTaskPackageRequest;
use App\Http\Requests\Tasks\StartTaskPrepFixRequest;
use App\Http\Requests\Tasks\StartTaskPrepRequest;
use App\Http\Requests\Tasks\StartTaskPublishRequest;
use App\Http\Requests\Tasks\StartTaskScriptRequest;
use App\Http\Requests\Tasks\StartTaskTurnRequest;
use App\Http\Requests\Tasks\StoreTaskCommentRequest;
use App\Http\Requests\Tasks\UpdateTaskGroupRequest;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TaskGroupsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::TaskGroupOwning)]
    public function store(CreateTaskGroupRequest $request, CreateTaskGroupAction $action, CreateTaskAction $childAction): JsonResponse
    {
        $caller = $request->user();
        $parent = $request->parentTask();
        $task = $parent === null
            ? $action->execute($request->parentPayload(), $caller instanceof Node ? $caller : null)
            : $childAction->execute($parent, $request->childPayload());

        return response()->json([
            'data' => ($task->parent_id === null ? TaskGroupData::fromModel($task) : TaskData::fromModel($task))->toArray(),
            'meta' => $this->meta($request),
        ], 201);
    }

    /** Starts tasks.turn and answers 202 with the queued operation. */
    #[StartsOperation(OperationKinds::TasksTurn, maxJsonBytes: PHP_INT_MAX)]
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function turn(StartTaskTurnRequest $request, Task $task, StartTaskTurnAction $action): JsonResponse
    {
        $requestId = $request->attributes->getString('orbit.request_id');
        $operation = $action->execute($task->parent()->firstOrFail(), $request->payload(), CallerName::fromRequest($request), $requestId === '' ? null : $requestId);

        return response()->json([
            'data' => OperationData::fromModel($operation)->toArray(),
            'meta' => $this->meta($request),
        ], 202);
    }

    /** Starts tasks.provision and answers 202 with the queued operation. */
    #[StartsOperation(OperationKinds::TasksProvision)]
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function provision(EmptyTasksRequest $request, Task $task, StartTaskProvisionAction $action): JsonResponse
    {
        $requestId = $request->attributes->getString('orbit.request_id');
        $operation = $action->execute($task, CallerName::fromRequest($request), $requestId === '' ? null : $requestId);

        return response()->json([
            'data' => OperationData::fromModel($operation)->toArray(),
            'meta' => $this->meta($request),
        ], 202);
    }

    /** Fetch the default branch and the task branch from origin into the VM workspace of an external group. */
    #[StartsOperation(OperationKinds::TasksSync, requiresKey: true)]
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function sync(EmptyTasksRequest $request, Task $task, StartTaskSyncAction $action): JsonResponse
    {
        $requestId = $request->attributes->getString('orbit.request_id');
        $operation = $action->execute($task, CallerName::fromRequest($request), $requestId === '' ? null : $requestId);

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => $this->meta($request)], 202);
    }

    /** Prepare the spec of one command and open its draft pull request in one run. */
    #[StartsOperation(OperationKinds::TasksPrep, requiresKey: true)]
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function prep(StartTaskPrepRequest $request, Task $task, StartTaskPrepAction $action): JsonResponse
    {
        $requestId = $request->attributes->getString('orbit.request_id');
        $operation = $action->execute($task, $request->payload(), CallerName::fromRequest($request), $requestId === '' ? null : $requestId);

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => $this->meta($request)], 202);
    }

    /** Apply the review of the owner to a parked prep group and push the corrections to its draft pull request. */
    #[StartsOperation(OperationKinds::TasksPrepFix, requiresKey: true)]
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function prepFix(StartTaskPrepFixRequest $request, Task $task, StartTaskPrepFixAction $action): JsonResponse
    {
        $requestId = $request->attributes->getString('orbit.request_id');
        $operation = $action->execute($task, $request->payload(), CallerName::fromRequest($request), $requestId === '' ? null : $requestId);

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => $this->meta($request)], 202);
    }

    #[StartsOperation(OperationKinds::TasksPublish)]
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function publish(StartTaskPublishRequest $request, Task $task, StartTaskPublishAction $action): JsonResponse
    {
        $requestId = $request->attributes->getString('orbit.request_id');
        $operation = $action->execute($task, $request->payload(), CallerName::fromRequest($request), $requestId === '' ? null : $requestId);

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => $this->meta($request)], 202);
    }

    #[StartsOperation(OperationKinds::TasksPackage, requiresKey: true)]
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function package(StartTaskPackageRequest $request, Task $task, StartTaskPackageAction $action): JsonResponse
    {
        $requestId = $request->attributes->getString('orbit.request_id');
        $operation = $action->execute($task, $request->payload(), CallerName::fromRequest($request), $requestId === '' ? null : $requestId);

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => $this->meta($request)], 202);
    }

    #[StartsOperation(OperationKinds::TasksScript, requiresKey: true)]
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function script(StartTaskScriptRequest $request, Task $task, StartTaskScriptAction $action): JsonResponse
    {
        $requestId = $request->attributes->getString('orbit.request_id');
        $operation = $action->execute($task, $request->payload(), CallerName::fromRequest($request), $requestId === '' ? null : $requestId);

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => $this->meta($request)], 202);
    }

    #[StartsOperation(OperationKinds::TasksCheck)]
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function check(StartTaskCheckRequest $request, Task $task, StartTaskCheckAction $action): JsonResponse
    {
        $requestId = $request->attributes->getString('orbit.request_id');
        $operation = $action->execute($task->parent()->firstOrFail(), $request->payload(), CallerName::fromRequest($request), $requestId === '' ? null : $requestId);

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => $this->meta($request)], 202);
    }

    #[RequiresNodeAccess(ServingNode::TaskGroupOwning)]
    public function update(UpdateTaskGroupRequest $request, Task $task, UpdateTaskGroupAction $action, UpdateTaskAction $childAction): JsonResponse
    {
        return response()->json([
            'data' => ($task->parent_id === null
                ? TaskGroupData::fromModel($action->execute($task, $request->parentPayload()))
                : TaskData::fromModel($childAction->execute($task->parent()->firstOrFail(), $task, $request->childPayload(), $request->user() instanceof Node ? $request->user() : null, $request->attributes->getString('orbit.request_id'))))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::TaskGroupOwning)]
    public function destroyTask(EmptyTasksRequest $request, Task $task, DestroyTaskAction $action): JsonResponse
    {

        return response()->json([
            'data' => TaskData::fromModel($action->execute($task->parent()->firstOrFail(), $task))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function cancelCheck(EmptyTasksRequest $request, Task $task, CancelTaskCheckAction $action): JsonResponse
    {

        return response()->json([
            'data' => TaskCheckData::fromModel($action->execute($task->parent()->firstOrFail(), $task))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function storeComment(StoreTaskCommentRequest $request, Task $task, StoreTaskCommentAction $action, StoreTaskGroupCommentAction $parentAction): JsonResponse
    {
        $comment = $task->parent_id === null
            ? $parentAction->execute($task, StoreTaskGroupCommentData::from($request->validated()), $request->attributes->getString('orbit.request_id'))
            : $action->execute($task, $request->validated(), $request->user() instanceof Node ? $request->user() : null, $request->attributes->getString('orbit.request_id'));

        return response()->json(['data' => TaskCommentData::fromModel($comment)->toArray(), 'meta' => $this->meta($request)], 201);
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function comments(ListTaskCommentsRequest $request, Task $task): JsonResponse
    {
        $comments = $task->comments()->latest('posted_at');

        if ($request->type() !== null) {
            $comments->where('type', $request->type());
        }
        if ($request->limit() !== null) {
            $comments->limit($request->limit());
        }

        return response()->json([
            'data' => $comments->get()->map(static fn (TaskComment $comment): array => TaskCommentData::fromModel($comment)->toArray())->all(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::Collection)]
    public function index(ListTaskGroupsRequest $request, ListTaskGroupsAction $action): JsonResponse
    {
        $tasks = $request->parentTask()?->tasks()
            ->when($request->validated('status'), static fn ($query, $status) => $query->where('status', $status))
            ->when($request->projectId(), static fn ($query, $project) => $query->whereHas('parent', static fn ($parent) => $parent->where('project_id', $project)))
            ->orderBy('position')->get() ?? $action->execute($request->projectId(), $request->status());
        $activity = TaskGroupActivity::of(array_values($tasks->whereNull('parent_id')->map(static fn (Task $group): int => $group->id)->all()));

        return response()->json([
            'data' => $tasks
                ->map(static function (Task $task) use ($request, $activity): array {
                    $data = $task->parent_id === null ? TaskGroupData::fromModel($task, $activity[$task->id]) : TaskData::fromModel($task);

                    return $request->boolean('compact') ? $data->toCompactArray() : $data->toArray();
                })
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::Collection)]
    public function show(ShowTaskGroupRequest $request, Task $task, ShowTaskGroupAction $action): JsonResponse
    {
        $data = $task->parent_id === null ? TaskGroupData::fromModel($action->execute($task)) : TaskData::fromModel($task);

        return response()->json([
            'data' => $request->boolean('compact') ? $data->toCompactArray() : $data->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function complete(EmptyTasksRequest $request, Task $task, CompleteTaskGroupAction $action): JsonResponse
    {
        return response()->json([
            'data' => TaskGroupData::fromModel($action->execute($task))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[StartsOperation('tasks.cancel', requiresKey: true)]
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function cancel(CancelTaskRequest $request, Task $task, TaskCancelIntent $action, CancelRunningSubtaskAction $childAction): JsonResponse
    {
        if ($task->parent_id !== null) {
            return response()->json(['data' => TaskData::fromModel($childAction->execute($task->parent()->firstOrFail(), $task))->toArray(), 'meta' => $this->meta($request)]);
        }

        $requestId = $request->attributes->getString('orbit.request_id');
        $operation = $action->start($task, CallerName::fromRequest($request), $requestId === '' ? null : $requestId, $request->discard());

        return response()->json(['data' => OperationData::summary($operation)->toArray(), 'meta' => $this->meta($request)], 202);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
