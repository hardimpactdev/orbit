<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\RecordInstanceReadyAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\DirectInstanceReadyRequest;
use App\Models\Operation;
use Illuminate\Http\JsonResponse;

/** The ready mark of a container clone's create run (docs/reference/operations.md#ready-mark-of-a-clone-create). */
final class DirectInstanceReadyController extends Controller
{
    public function ready(DirectInstanceReadyRequest $request, Operation $operation, RecordInstanceReadyAction $action): JsonResponse
    {
        $readyAt = $action->execute($operation, $request->runId(), $request->t3());

        return response()->json(['data' => ['ready_at' => $readyAt], 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }
}
