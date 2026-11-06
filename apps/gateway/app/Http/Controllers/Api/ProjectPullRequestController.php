<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Projects\StartProjectPullRequestMergeAction;
use App\Data\Operations\OperationData;
use App\Domain\Operations\OperationKinds;
use App\Http\Authorization\CallerName;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\StartsOperation;
use App\Http\Requests\Projects\ProjectPullRequestMergeRequest;
use App\Models\Project;
use Illuminate\Http\JsonResponse;

final class ProjectPullRequestController extends Controller
{
    /** Squash-merges a pull request that no Task group watches, on the exact head, once the merge check passed on it. */
    #[StartsOperation(OperationKinds::ProjectPrMerge, requiresKey: true, bindCaller: true)]
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function merge(ProjectPullRequestMergeRequest $request, Project $project, StartProjectPullRequestMergeAction $action): JsonResponse
    {
        $operation = $action->execute($project, $request->integer('pull_request'), $request->string('head_sha')->toString(),
            CallerName::fromRequest($request), $request->attributes->getString('orbit.request_id'));

        return response()->json(['data' => OperationData::fromModel($operation)->toArray(), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]], 202);
    }
}
