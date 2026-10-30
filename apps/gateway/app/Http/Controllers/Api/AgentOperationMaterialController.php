<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Instances\StepLists\InstanceRunStepMaterial;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\ReadAgentStepMaterialRequest;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Http\JsonResponse;
use Throwable;

/** Private agent material never enters activity, cache, or public Operation state. */
final class AgentOperationMaterialController extends Controller
{
    public function show(ReadAgentStepMaterialRequest $request, Operation $operation, InstanceRunStepMaterial $material): JsonResponse
    {
        try {
            $node = $request->getUserResolver()();
            if (! $node instanceof Node) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The Node agent identity is required.', 403);
            }
            $data = $material->read($operation, $node->id,
                ValidatedData::string($request->validated('step')), ValidatedData::string($request->validated('digest')));
            $response = response()->json(['data' => $data]);
            $content = $response->getContent();
            if (! is_string($content) || strlen($content) > 2 * 1024 * 1024) {
                throw new ResourceOperationException('instance.step_not_admitted', 'The app-user step material exceeds its bound.', 409);
            }

            return $response->header('Cache-Control', 'private, no-store');
        } catch (Throwable) {
            // Material errors may contain protected values; never report or serialize them.
            return response()->json(['error' => ['code' => 'instance.step_not_admitted',
                'message' => 'The app-user step is not admitted.', 'details' => []]], 409)
                ->header('Cache-Control', 'private, no-store');
        }
    }
}
