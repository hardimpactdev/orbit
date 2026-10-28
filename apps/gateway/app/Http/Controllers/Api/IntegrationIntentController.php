<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Integrations\IntegrationIntent;
use App\Domain\Operations\SwampOperationExecutor;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\HeartbeatExecutorRequest;
use App\Models\Operation;
use Illuminate\Http\JsonResponse;

/** A Services-only read of the safe plan recorded by the named preparation command. */
#[RequiresNodeAccess(ServingNode::Gateway)]
final class IntegrationIntentController extends Controller
{
    public function show(HeartbeatExecutorRequest $request, Operation $operation, SwampOperationExecutor $executor): JsonResponse
    {
        $operation = $executor->heartbeat($operation, $request->worker());
        if ($operation->kind !== 'ai_router.collector_converge' || ! isset($operation->state['integration_intent'])) {
            throw new ResourceOperationException('operation.execution_forbidden', 'This Operation has no prepared integration intent.', 422);
        }

        return response()->json(['data' => IntegrationIntent::data($operation), 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }
}
