<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\GitHub\IssueDirectGitHubTokenAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Tasks\TaskCheckGitHubTokens;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\DirectGitHubTokenRequest;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Http\JsonResponse;

final class DirectGitHubTokenController extends Controller
{
    public function mint(DirectGitHubTokenRequest $request, Operation $operation, IssueDirectGitHubTokenAction $issue): JsonResponse
    {
        $issuer = app(DirectOperation::class)->definition($operation->kind)['github_token_issuer'] ?? null;
        if ($issuer !== null) {
            return response()->json(['data' => ['execution_secrets' => app($issuer)->issue($operation, $request->peerNodeId(), ValidatedData::string($request->validated('run_id')))], 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]])->header('Cache-Control', 'private, no-store');
        }
        if ($operation->kind === 'tasks.check') {
            $copies = app(TaskCheckGitHubTokens::class)->issue($operation, $request->peerNodeId(), ValidatedData::string($request->validated('run_id')));

            return response()->json(['data' => ['execution_secrets' => $copies], 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]])->header('Cache-Control', 'private, no-store');
        }
        $issued = $issue->handle($operation, $request->peerNodeId(), ValidatedData::string($request->validated('run_id')));
        $grant = $issued['grant'];
        $credential = $issued['credential'];

        return response()->json(['data' => ['github' => [
            'repository' => $grant->repository->owner.'/'.$grant->repository->name,
            'permissions' => $grant->permissions, 'token' => $credential->reveal(), 'expires_at' => $credential->expiresAt,
        ]], 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]])->header('Cache-Control', 'private, no-store');
    }
}
