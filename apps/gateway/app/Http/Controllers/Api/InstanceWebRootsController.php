<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Instances\Environment\DirectInstanceWebRoots;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instances\InstanceWebRootsRequest;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class InstanceWebRootsController extends Controller
{
    public function show(InstanceWebRootsRequest $request, Operation $operation): JsonResponse
    {
        return $this->respond($request, $this->operation($operation));
    }

    public function prepare(InstanceWebRootsRequest $request, Operation $operation, DirectInstanceWebRoots $roots): JsonResponse
    {
        if ($operation->status->terminal()) {
            throw new ResourceOperationException('operation.finished', 'The web-root Operation has ended.', 409);
        }

        return $this->respond($request, $roots->prepare($operation));
    }

    public function result(InstanceWebRootsRequest $request, Operation $operation, DirectOperation $direct, OperationExecutionContract $contract): JsonResponse
    {
        $body = $request->validated();
        $result = $contract->result($operation, ValidatedData::object($body['result'] ?? []));
        $status = $body['status'];
        $code = $body['error_code'] ?? null;
        if (($status === 'succeeded' && ($result !== ['instance_id' => $operation->target_id, 'confirmed' => true, 'no_op' => false]
                && $result !== ['instance_id' => $operation->target_id, 'confirmed' => true, 'no_op' => true]))
            || ($status === 'failed' && ($code === null || $result !== ['error_code' => $code]))
            || (in_array($status, ['running', 'cancelled'], true) && $result !== [])
            || ($status !== 'failed' && $code !== null)) {
            throw new ResourceOperationException('operation.result_invalid', 'The web-root result does not match the native outcome.', 422);
        }

        return $this->respond($request, $this->operation($direct->project($operation, $body, $result, 'web_roots')));
    }

    /** @return array<string, mixed> */
    private function operation(Operation $operation): array
    {
        return ['id' => $operation->id, 'kind' => $operation->kind, 'node_id' => $operation->node_id,
            'status' => $operation->status->value, 'result' => $operation->result, 'cancel_requested' => $operation->cancel_requested_at !== null];
    }

    /** @param array<string, mixed> $data */
    private function respond(InstanceWebRootsRequest $request, array $data): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')]]);
    }
}
