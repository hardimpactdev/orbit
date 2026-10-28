<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Operations\SwampOperationExecutor;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\ClaimExecutorRequest;
use App\Http\Requests\Operations\CompleteExecutorRequest;
use App\Http\Requests\Operations\ExecutorRequest;
use App\Http\Requests\Operations\HeartbeatExecutorRequest;
use App\Http\Requests\Operations\RunExecutorRequest;
use App\Http\Requests\Operations\StepExecutorRequest;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class OperationExecutorController extends Controller
{
    public function claim(ClaimExecutorRequest $request, SwampOperationExecutor $executor): JsonResponse
    {
        $operation = $executor->claim(ValidatedData::string($request->validated('kind')), $request->worker());

        return $this->respond($request, $operation === null ? null : $this->receipt($operation));
    }

    public function heartbeat(HeartbeatExecutorRequest $request, Operation $operation, SwampOperationExecutor $executor): JsonResponse
    {
        return $this->respond($request, $this->receipt($executor->heartbeat($operation, $request->worker())));
    }

    public function step(StepExecutorRequest $request, Operation $operation, SwampOperationExecutor $executor): JsonResponse
    {
        return $this->respond($request, $this->receipt($executor->step($operation, $request->stepInput())));
    }

    public function complete(CompleteExecutorRequest $request, Operation $operation, SwampOperationExecutor $executor): JsonResponse
    {
        return $this->respond($request, $this->receipt($executor->complete($operation, $request->completionInput())));
    }

    public function run(RunExecutorRequest $request, Operation $operation, SwampOperationExecutor $executor): JsonResponse
    {
        return $this->respond($request, $executor->run($operation, $request->runInput()));
    }

    /** @param array<string, mixed>|null $data */
    private function respond(ExecutorRequest $request, ?array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    /** @return array<string, mixed> */
    private function receipt(Operation $operation): array
    {
        return [...OperationsController::withSteps($operation, null, null),
            'node_id' => $operation->node_id,
            'worker' => $operation->worker,
            'lease_seconds' => SwampOperationExecutor::LeaseSeconds,
            'step_results' => $operation->state['steps'] ?? [],
        ];
    }
}
