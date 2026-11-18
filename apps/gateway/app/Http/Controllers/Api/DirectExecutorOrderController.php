<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Instances\Power\ExecutorFence;
use App\Domain\Instances\Power\ExecutorPowerIntent;
use App\Domain\Instances\Power\ExecutorWake;
use App\Domain\Nodes\SwampNode;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\SignDirectExecutorOrderRequest;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class DirectExecutorOrderController extends Controller
{
    public function sign(SignDirectExecutorOrderRequest $request, Operation $operation, DirectOperation $direct): JsonResponse
    {
        if ($request->peerNodeId() !== SwampNode::idOrNull()) {
            throw new ResourceOperationException('operation.executor_forbidden', 'Only the Swamp worker may request an executor order.', 403);
        }
        $phase = ValidatedData::string($request->validated('phase'));
        $runId = ValidatedData::string($request->validated('run_id'));
        $order = DB::transaction(static function () use ($direct, $operation, $phase, $runId): string {
            $current = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $direct->assertRun($current, $runId);
            if ($current->status !== OperationStatus::Running || $current->cancel_requested_at !== null) {
                throw new ResourceOperationException('operation.finished', 'This Operation no longer permits executor signing.', 409);
            }
            if (ExecutorPowerIntent::action($current, $phase) === null || $current->node_id === null
                || ($phase === 'power' && ($current->state[ExecutorWake::Recovery] ?? false) !== true)) {
                throw new ResourceOperationException('executor.intent_missing', 'This phase has no admitted executor action.', 403);
            }

            $wire = ExecutorPowerIntent::sign($current, $phase)->wire;
            // ADR 0266, R2: from here the order may reach the Node, so the Operation keeps its fence until a receipt exists.
            $current->forceFill(['state' => ExecutorFence::issue($current->state)])->save();

            return $wire;
        });

        return response()->json(['data' => ['order' => $order]])->header('Cache-Control', 'private, no-store');
    }
}
