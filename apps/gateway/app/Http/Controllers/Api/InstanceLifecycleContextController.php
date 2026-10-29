<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Instances\RemovalLifecycleExecution;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationFamilyChildren;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instances\LifecycleContextRequest;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Models\Operation;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class InstanceLifecycleContextController extends Controller
{
    public function __invoke(LifecycleContextRequest $request, Operation $operation, OperationExecutionContract $contract, CommandActivityInputSanitizer $redactor): JsonResponse
    {
        $input = match ($operation->kind) {
            'instance.destroy' => app(RemovalLifecycleExecution::class)->intent($operation),
            'instance.create', 'instance.register', 'instance.rename' => $operation->input,
            default => throw new ResourceOperationException('operation.execution_forbidden', 'The Operation is outside the lifecycle family.', 422),
        };
        $execution = $contract->execution($operation);
        if (! $execution instanceof OperationFamilyChildren) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The lifecycle contract is unavailable.', 422);
        }

        return response()->json(['data' => [
            'id' => $operation->id, 'kind' => $operation->kind, 'node_id' => $operation->node_id,
            'input' => $redactor->sanitizeProperties($input), 'execution_context' => $execution->context($operation),
            'child_context' => $execution->childContext($operation),
        ], 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }
}
