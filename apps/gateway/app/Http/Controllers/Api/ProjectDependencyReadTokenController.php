<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\GitHub\IssueDependencyReadTokenAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\GitHub\DependencyReadTokenRequest;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class ProjectDependencyReadTokenController extends Controller
{
    public function store(DependencyReadTokenRequest $request, int $project, IssueDependencyReadTokenAction $action): JsonResponse
    {
        $result = $action->handle($project);
        $identity = strtolower($result['repository']->owner.'/'.$result['repository']->name);

        return response()->json(['data' => [
            'project_id' => $result['project']->id,
            'repository' => 'github.com/'.$identity,
            'clone_url' => 'https://github.com/'.$identity.'.git',
            'token' => $result['credential']->reveal(),
            'expires_at' => $result['credential']->expiresAt,
        ]])->header('Cache-Control', 'no-store');
    }
}
