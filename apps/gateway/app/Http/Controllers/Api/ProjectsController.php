<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Projects\CreateProjectAction;
use App\Actions\Projects\DestroyProjectAction;
use App\Actions\Projects\ListProjectsAction;
use App\Actions\Projects\ShowProjectAction;
use App\Actions\Projects\UpdateProjectAction;
use App\Data\Projects\DevelopmentNodeExclusionData;
use App\Data\Projects\ProjectData;
use App\Data\Projects\UpdateProjectData;
use App\Domain\Projects\DevelopmentNodeExclusion;
use App\Domain\Projects\ReviewAndMergeRequirements;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProjectNodeExclusion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ProjectsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Collection)]
    public function index(Request $request, ListProjectsAction $action): JsonResponse
    {
        $consumer = $request->user();
        assert($consumer instanceof Node, description: 'Authenticated peer must be a Node.');

        return response()->json([
            'data' => $action
                ->handle($consumer)
                ->map(static fn (Project $project): array => ProjectData::fromModel($project)->toArray())
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function store(StoreProjectRequest $request, CreateProjectAction $action): JsonResponse
    {
        $result = $action->execute($request->payload());

        return response()->json(
            [
                'data' => ProjectData::fromModel($result['project'])->toArray(),
                'meta' => $this->meta($request),
            ],
            $result['created'] ? 201 : 200,
        );
    }

    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function show(Request $request, Project $project, ShowProjectAction $action): JsonResponse
    {
        return response()->json([
            'data' => [
                ...ProjectData::fromModel($action->handle($project))->toArray(),
                'excluded_nodes' => app(DevelopmentNodeExclusion::class)->forProject($project)
                    ->map(static fn (ProjectNodeExclusion $exclusion): array => DevelopmentNodeExclusionData::fromModel($exclusion)->toArray())
                    ->values()
                    ->all(),
            ],
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function update(UpdateProjectRequest $request, Project $project, UpdateProjectAction $action): JsonResponse
    {
        return response()->json([
            'data' => ProjectData::fromModel($action->execute($project, $request->payload()))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    /** Review and merge keeps reviewing and merging by itself, so it is an enable pair, not an update flag. */
    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function enableReviewAndMerge(Request $request, Project $project, UpdateProjectAction $action): JsonResponse
    {
        $missing = ReviewAndMergeRequirements::missing($project->merge_check, $project->source_access);
        if ($missing !== []) {
            throw ValidationException::withMessages(array_map(static fn (string $message): array => [$message], $missing));
        }

        return $this->reviewAndMerge($request, $project, $action, true);
    }

    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function disableReviewAndMerge(Request $request, Project $project, UpdateProjectAction $action): JsonResponse
    {
        return $this->reviewAndMerge($request, $project, $action, false);
    }

    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function destroy(Request $request, Project $project, DestroyProjectAction $action): JsonResponse
    {
        return response()->json([
            'data' => ProjectData::fromModel($action->execute($project))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }

    private function reviewAndMerge(Request $request, Project $project, UpdateProjectAction $action, bool $enabled): JsonResponse
    {
        $data = new UpdateProjectData(typeProvided: false, type: null, slugProvided: false, slug: null, repositoryUrlProvided: false, repositoryUrl: null,
            defaultBranchProvided: false, defaultBranch: null, rootProvided: false, root: null, reviewAndMergeProvided: true, reviewAndMerge: $enabled);

        return response()->json([
            'data' => ProjectData::fromModel($action->execute($project, $data))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }
}
