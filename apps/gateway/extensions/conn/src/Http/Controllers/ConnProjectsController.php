<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Controllers;

use App\Actions\Instances\StartDevelopmentInstanceCreateAction;
use App\Data\Instances\CreateInstanceData;
use App\Data\Operations\OperationData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\StartsOperation;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Orbit\Extensions\Conn\Actions\CheckConnInstanceNameAction;
use Orbit\Extensions\Conn\Data\ConnProjectData;
use Orbit\Extensions\Conn\Domain\ConnThreadProjects;
use Orbit\Extensions\Conn\Http\Requests\CheckConnInstanceNameRequest;
use Orbit\Extensions\Conn\Http\Requests\CreateConnInstanceRequest;

/** Threads in an Orbit Project (/reference/conn#threads-in-an-orbit-project): the Projects a device reaches, and the clone a thread starts in. */
#[RequiresNodeAccess(ServingNode::Collection)]
final class ConnProjectsController extends Controller
{
    public function index(Request $request, ConnThreadProjects $projects): JsonResponse
    {
        return response()->json([
            'data' => $projects->all($this->caller($request))
                ->map(static fn (ConnProjectData $project): array => $project->toArray())
                ->all(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    public function checkInstanceName(CheckConnInstanceNameRequest $request, Project $project, CheckConnInstanceNameAction $action): JsonResponse
    {
        $data = $action->execute($project, $request->default(), $request->name());

        return response()->json(['data' => $data->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    /** The same `instance.create` as instance:create, on the Node of the Project's default; the device follows it with Conn Operation show. */
    #[StartsOperation('instance.create', bindCaller: true)]
    public function createInstance(CreateConnInstanceRequest $request, Project $project, ConnThreadProjects $projects,
        StartDevelopmentInstanceCreateAction $action): JsonResponse
    {
        $caller = $this->caller($request);
        $projects->assertNameFree($caller, $project, $request->name());
        $operation = $action->execute(new CreateInstanceData($project->id, $request->default()->node_id, $request->name(), null, null, null),
            'node:'.$caller->id, $request->attributes->getString('orbit.request_id'));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }

    private function caller(Request $request): Node
    {
        $caller = $request->user();
        assert($caller instanceof Node, description: 'Authenticated peer must be a Node.');

        return $caller;
    }
}
