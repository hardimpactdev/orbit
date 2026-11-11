<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Http\Controllers;

use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Orbit\Extensions\Conn\Data\ConnProjectBranchData;
use Orbit\Extensions\Conn\Domain\ConnProjectBranches;
use Orbit\Extensions\Conn\Http\Requests\ListConnProjectBranchesRequest;

#[RequiresNodeAccess(ServingNode::Collection)]
final class ConnProjectBranchesController extends Controller
{
    public function index(ListConnProjectBranchesRequest $request, Project $project, ConnProjectBranches $branches): JsonResponse
    {
        $caller = $request->user();
        assert($caller instanceof Node);
        $result = $branches->read($caller, $project, $request->nameQuery());

        return response()->json([
            'data' => array_map(static fn (ConnProjectBranchData $branch): array => $branch->toArray(), $result['data']),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id'), 'pull_requests_unavailable' => $result['pull_requests_unavailable']],
        ]);
    }
}
