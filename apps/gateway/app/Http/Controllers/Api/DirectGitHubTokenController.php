<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Operations\DirectOperation;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\DirectGitHubTokenRequest;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Http\JsonResponse;

final class DirectGitHubTokenController extends Controller
{
    public function mint(DirectGitHubTokenRequest $request, Operation $operation): JsonResponse
    {
        $issuer = app(DirectOperation::class)->definition($operation->kind)['github_token_issuer'] ?? null;
        if ($issuer !== null) {
            return response()->json(['data' => ['execution_secrets' => app($issuer)->issue($operation, $request->peerNodeId(), ValidatedData::string($request->validated('run_id')), $request->validated('purpose'))], 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]])->header('Cache-Control', 'private, no-store');
        }
        throw new ResourceOperationException('operation.github_token_forbidden', 'This intent declares no GitHub credential.', 403);
    }
}
