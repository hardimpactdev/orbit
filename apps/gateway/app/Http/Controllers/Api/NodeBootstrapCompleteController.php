<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Models\Node;
use App\Models\NodeBootstrap;
use App\Models\OperationRun;
use App\Services\ActivityLogger;
use App\Services\Nodes\GatewayNodeCreator;
use App\Services\Nodes\NodeBootstrapCompletedActivity;
use App\Services\Operations\OperationRunRecorder;
use App\Support\Streaming\ProgressEventStreamEmitter;
use App\Support\Streaming\ProgressEventStreamResponseFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** @mago-expect lint:cyclomatic-complexity */
#[RequiresPermission('node:new', servingNode: ServingNode::Gateway)]
final readonly class NodeBootstrapCompleteController
{
    public function __construct(
        private ActivityLogger $activityLogger,
    ) {}

    public function __invoke(
        Request $request,
        NodeBootstrap $nodeBootstrap,
        GatewayNodeCreator $nodes,
        ProgressEventStreamResponseFactory $streams,
        OperationRunRecorder $operationRuns,
    ): JsonResponse|StreamedResponse {
        /** @var mixed $resolvedUser */
        $resolvedUser = $request->user();
        $caller = $resolvedUser instanceof Node ? $resolvedUser : null;

        if (! $caller instanceof Node) {
            return response()->json([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'This caller cannot complete node bootstrap.',
                    'meta' => [],
                ],
            ], 403);
        }

        if ($nodeBootstrap->initiating_node_id !== $caller->id) {
            return response()->json([
                'error' => [
                    'code' => 'authorization_failed',
                    'message' => 'Only the initiating node can complete this bootstrap.',
                    'meta' => ['bootstrap_id' => $nodeBootstrap->id],
                ],
            ], 403);
        }

        if (! in_array('text/event-stream', $request->getAcceptableContentTypes(), strict: true)) {
            $completion = $nodes->completeBootstrap($nodeBootstrap, $caller);
            $result = $completion->result;

            if ($result->successful() && $completion->completedNow) {
                $this->activityLogger->log(
                    new NodeBootstrapCompletedActivity($nodeBootstrap),
                    channel: 'api',
                    causer: $caller,
                );
            }

            return response()->json(
                $result->payload,
                $result->status(static fn (array $payload): int => ($payload['error']['code'] ?? null)
                    === 'authorization_failed'
                        ? 403
                        : 422),
            );
        }

        $operationRun = $operationRuns->queued(
            operationId: (string) Str::uuid(),
            lane: 'gateway',
            internalCommand: 'node:new',
            operationType: 'node:new',
            callerNodeId: $caller->id,
            targetNodeId: $nodeBootstrap->node_id,
        );
        $activityLogger = $this->activityLogger;

        return $streams->make(function (ProgressEventStreamEmitter $events) use (
            $nodes,
            $nodeBootstrap,
            $caller,
            $operationRuns,
            $operationRun,
            $activityLogger,
        ): void {
            $events->tree('Completing Node Bootstrap', [
                ['key' => 'agent', 'label' => 'Wait for WireGuard Agent'],
                ['key' => 'node', 'label' => 'Converge node'],
            ]);
            $events->stepEvent('agent', 'running', 'Waiting for the WireGuard-bound Agent');

            $operationRun = $operationRuns->running($operationRun->id);

            try {
                $completion = $nodes->completeBootstrap($nodeBootstrap, $caller);
                $result = $completion->result;

                if ($result->successful()) {
                    if ($completion->completedNow) {
                        $activityLogger->log(
                            new NodeBootstrapCompletedActivity($nodeBootstrap),
                            channel: 'api',
                            causer: $caller,
                        );
                    }

                    $operationRun = $operationRuns->succeeded($operationRun->id, 0, $result->payload);
                    $events->stepEvent('agent', 'done', 'WireGuard-bound Agent ready');
                    $events->stepEvent('node', 'done', 'Node converged');
                    $events->complete(0, [
                        'footer' => 'Node bootstrap complete.',
                        'operation_run' => $this->operationRunPayload($operationRun),
                        'result' => $result->payload,
                    ]);

                    return;
                }

                $error = $this->errorFramePayload($result->payload);
                $message = $error['message'];
                $operationRun = $operationRuns->failed($operationRun->id, $result->exitCode, $error);
                $events->stepEvent('agent', 'fail', $message);
                $events->stepEvent('node', 'skip', 'Node convergence did not complete');
                $events->error($message, 1, [
                    'code' => $error['code'],
                    'meta' => $error['meta'],
                    'operation_run' => $this->operationRunPayload($operationRun),
                ]);
            } catch (Throwable $exception) {
                $error = [
                    'code' => 'node.provisioning_incomplete',
                    'message' => $exception->getMessage() !== ''
                        ? $exception->getMessage()
                        : 'Node bootstrap failed.',
                    'meta' => ['bootstrap_id' => $nodeBootstrap->id],
                ];
                $operationRun = $operationRuns->failed($operationRun->id, 1, $error);
                $events->stepEvent('agent', 'fail', $error['message']);
                $events->stepEvent('node', 'skip', 'Node convergence did not complete');
                $events->error($error['message'], 1, [
                    ...$error,
                    'operation_run' => $this->operationRunPayload($operationRun),
                ]);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function operationRunPayload(OperationRun $operationRun): array
    {
        return [
            'id' => $operationRun->id,
            'operation_id' => $operationRun->operation_id,
            'type' => $operationRun->operation_type,
            'status' => $operationRun->status->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{code: string, message: string, meta: array<string, mixed>}
     */
    private function errorFramePayload(array $payload): array
    {
        /** @var mixed $rawError */
        $rawError = $payload['error'] ?? null;
        $error = is_array($rawError) ? $rawError : [];
        /** @var mixed $rawMeta */
        $rawMeta = $error['meta'] ?? null;
        $meta = [];

        if (is_array($rawMeta)) {
            foreach ($rawMeta as $key => $value) {
                if (is_string($key)) {
                    $meta[$key] = $value;
                }
            }
        }

        return [
            'code' => is_string($error['code'] ?? null)
                ? $error['code']
                : 'node.provisioning_incomplete',
            'message' => is_string($error['message'] ?? null)
                ? $error['message']
                : 'Node bootstrap failed.',
            'meta' => $meta,
        ];
    }
}
