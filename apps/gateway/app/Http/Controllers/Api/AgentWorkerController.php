<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Nodes\IssueNodeWorkerAction;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Controllers\Controller;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentWorkerController extends Controller
{
    public function __invoke(Request $request, IssueNodeWorkerAction $issue): JsonResponse
    {
        $node = $request->user();
        if (! $node instanceof Node || $request->all() !== []) {
            throw new ResourceOperationException('swamp.worker_request_invalid', 'Worker renewal accepts only the authenticated Node identity.', 422);
        }

        return response()->json($issue->execute($node))->header('Cache-Control', 'no-store');
    }
}
