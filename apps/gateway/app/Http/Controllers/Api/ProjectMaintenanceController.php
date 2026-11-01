<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Data\Operations\OperationData;
use App\Domain\Projects\ProjectMaintenanceIntent;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\StartsOperation;
use App\Http\Requests\Projects\ProjectMaintenanceRequest;
use App\Models\Project;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class ProjectMaintenanceController extends Controller
{
    #[StartsOperation('project.detect', requiresKey: true)]
    public function detect(ProjectMaintenanceRequest $request, Project $project, ProjectMaintenanceIntent $intent): JsonResponse
    {
        $operation = $intent->start($project, 'detect', $request->attributes->getString('orbit.request_id'));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }

    #[StartsOperation('project.routine', requiresKey: true)]
    public function routine(ProjectMaintenanceRequest $request, Project $project, ProjectMaintenanceIntent $intent): JsonResponse
    {
        $operation = $intent->start($project, 'routine', $request->attributes->getString('orbit.request_id'));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }
}
