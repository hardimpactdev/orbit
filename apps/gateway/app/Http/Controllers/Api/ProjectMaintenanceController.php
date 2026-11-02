<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Data\Operations\OperationData;
use App\Data\Projects\ProjectData;
use App\Domain\Projects\ProjectMaintenanceIntent;
use App\Domain\Projects\ProjectMaintenanceRequirements;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\StartsOperation;
use App\Http\Requests\Projects\ProjectMaintenanceRequest;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class ProjectMaintenanceController extends Controller
{
    /** Maintenance keeps inspecting and opening update groups by itself, so it is an enable pair, not an update flag. */
    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function enable(Request $request, Project $project): JsonResponse
    {
        return $this->maintenance($request, $project, true);
    }

    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function disable(Request $request, Project $project): JsonResponse
    {
        return $this->maintenance($request, $project, false);
    }

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

    private function maintenance(Request $request, Project $project, bool $enabled): JsonResponse
    {
        $project = DB::transaction(static function () use ($project, $enabled): Project {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            $missing = $enabled ? ProjectMaintenanceRequirements::missing($project) : [];
            if ($missing !== []) {
                throw ValidationException::withMessages(array_map(static fn (string $message): array => [$message], $missing));
            }
            $project->update(['maintenance' => $enabled]);

            return $project;
        });

        return response()->json(['data' => ProjectData::fromModel($project)->toArray(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }
}
