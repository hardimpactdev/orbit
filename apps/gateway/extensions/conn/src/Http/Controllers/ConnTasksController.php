<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Controllers;

use App\Actions\Tasks\ListTaskGroupsAction;
use App\Actions\Tasks\ShowTaskGroupAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Models\Node;
use App\Models\Task;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Extensions\Conn\Data\ConnTaskData;
use Orbit\Extensions\Conn\Domain\ConnTaskPanel;
use Orbit\Extensions\Conn\Http\Requests\ListConnTasksRequest;

/** The read-only Tasks panel of Conn ([Tasks panel](/reference/conn#tasks-panel)): the task list and show, read for a device. */
#[RequiresNodeAccess(ServingNode::Collection)]
final class ConnTasksController extends Controller
{
    public function index(ListConnTasksRequest $request, ListTaskGroupsAction $action, ConnTaskPanel $panel): JsonResponse
    {
        $page = $action->execute($request->projectId(), $request->status(), $request->limit(), $request->cursor(), $request->instanceId());

        return response()->json([
            'data' => array_map(static fn (ConnTaskData $row): array => $row->toArray(), $panel->rows($this->caller($request), $page['groups'])),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id'), 'next_cursor' => $page['next_cursor']],
        ]);
    }

    /** A top-level task only: a subtask is read inside its task. */
    public function show(Request $request, Task $task, ShowTaskGroupAction $action, ConnTaskPanel $panel): JsonResponse
    {
        if ($task->parent_id !== null) {
            throw (new ModelNotFoundException)->setModel(Task::class, [$task->id]);
        }

        return response()->json([
            'data' => $panel->detail($this->caller($request), $action->execute($task))->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    private function caller(Request $request): Node
    {
        $caller = $request->user();
        assert($caller instanceof Node, description: 'Authenticated peer must be a Node.');

        return $caller;
    }
}
