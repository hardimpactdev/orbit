<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\Dependencies\ReadSwampDependenciesAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\DependencyResultsRequest;
use App\Models\Project;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::ProjectOwning)]
final class ProjectDependenciesController extends Controller
{
    public function show(DependencyResultsRequest $request, Project $project, ReadSwampDependenciesAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action->execute($project, null, $request->source(), $request->integer('packages_page', 1), $request->integer('findings_page', 1))->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ])->header('Cache-Control', 'no-store');
    }
}
