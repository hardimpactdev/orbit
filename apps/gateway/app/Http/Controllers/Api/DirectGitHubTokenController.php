<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\GitHub\IssueDirectGitHubTokenAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\DirectOperationRequest;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Http\JsonResponse;

final class DirectGitHubTokenController extends Controller
{
    public function mint(DirectOperationRequest $request, Operation $operation, IssueDirectGitHubTokenAction $issue): JsonResponse
    {
        $issued = $issue->handle($operation, $request->peerNodeId(), ValidatedData::string($request->validated('run_id')));
        $grant = $issued['grant'];
        $credential = $issued['credential'];

        return response()->json(['data' => ['github' => [
            'repository' => $grant->repository->owner.'/'.$grant->repository->name,
            'permissions' => $grant->permissions, 'token' => $credential->reveal(), 'expires_at' => $credential->expiresAt,
        ]], 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]])->header('Cache-Control', 'private, no-store');
    }
}
