<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\SwampOperationExecutor;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\ChildrenExecutorRequest;
use App\Http\Requests\Operations\ClaimExecutorRequest;
use App\Http\Requests\Operations\CompleteExecutorRequest;
use App\Http\Requests\Operations\ExecutorRequest;
use App\Http\Requests\Operations\HeartbeatExecutorRequest;
use App\Http\Requests\Operations\ReadChildrenExecutorRequest;
use App\Http\Requests\Operations\RunExecutorRequest;
use App\Http\Requests\Operations\StepExecutorRequest;
use App\Http\Requests\Operations\WaitExecutorRequest;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class OperationExecutorController extends Controller
{
    public function claim(ClaimExecutorRequest $request, SwampOperationExecutor $executor): JsonResponse
    {
        $operation = $executor->claim(ValidatedData::string($request->validated('kind')), $request->worker(), $request->peerNodeId(), $request->validated('operation_id') === null ? null : $request->integer('operation_id'));

        return $this->respond($request, $operation === null ? null : $this->receipt($operation));
    }

    public function heartbeat(HeartbeatExecutorRequest $request, Operation $operation, SwampOperationExecutor $executor): JsonResponse
    {
        return $this->respond($request, $this->receipt($executor->heartbeat($operation, $request->worker(), $request->peerNodeId())));
    }

    public function step(StepExecutorRequest $request, Operation $operation, SwampOperationExecutor $executor): JsonResponse
    {
        return $this->respond($request, $this->receipt($executor->step($operation, $request->stepInput(), $request->peerNodeId())));
    }

    public function complete(CompleteExecutorRequest $request, Operation $operation, SwampOperationExecutor $executor): JsonResponse
    {
        return $this->respond($request, $this->receipt($executor->complete($operation, $request->completionInput(), $request->peerNodeId())));
    }

    public function run(RunExecutorRequest $request, Operation $operation, SwampOperationExecutor $executor): JsonResponse
    {
        return $this->respond($request, $executor->run($operation, $request->runInput(), $request->peerNodeId()));
    }

    public function children(ChildrenExecutorRequest $request, Operation $operation, SwampOperationExecutor $executor): JsonResponse
    {
        $child = $executor->child($operation, $request->validated(), $request->peerNodeId());

        return $this->respond($request, $this->receipt($child['operation']))->setStatusCode($child['created'] ? 202 : 200);
    }

    public function readChildren(ReadChildrenExecutorRequest $request, Operation $operation, OperationChildren $children): JsonResponse
    {
        return $this->respond($request, $children->receipts($operation));
    }

    public function wait(WaitExecutorRequest $request, Operation $operation, SwampOperationExecutor $executor): JsonResponse
    {
        return $this->respond($request, $this->receipt($executor->wait($operation, $request->worker(), ValidatedData::stringList($request->validated('keys')), $request->peerNodeId())));
    }

    /** @param array<string, mixed>|list<array<string,mixed>>|null $data */
    private function respond(ExecutorRequest $request, ?array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }

    /** @return array<string, mixed> */
    private function receipt(Operation $operation): array
    {
        $wait = ValidatedData::object($operation->state['executor_wait'] ?? []);
        $tuples = is_array($wait['children'] ?? null) ? $wait['children'] : [];

        return [...OperationsController::withSteps($operation, null, null),
            'node_id' => $operation->node_id,
            'parent_operation_id' => $operation->parent_operation_id, 'child_key' => $operation->child_key,
            'worker' => $operation->worker,
            'lease_seconds' => $operation->status === OperationStatus::Waiting ? 0 : SwampOperationExecutor::LeaseSeconds,
            'waiting_for' => array_map(static fn (mixed $tuple): array => ['key' => ValidatedData::object($tuple)['key'], 'id' => ValidatedData::object($tuple)['id']], $tuples),
            'step_results' => $operation->state['steps'] ?? [],
            'execution_context' => app(OperationExecutionContract::class)->context($operation),
        ];
    }
}
