<?php

declare(strict_types=1);

namespace App\Services\Deploy;

use App\Models\Node;
use App\Models\OperationRun;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\OperationStreamFrameBroadcaster;
use App\Services\Operations\OperationStreamProgressReporter;
use Illuminate\Support\Str;
use Orbit\Sdk\Laravel\GatewayApiException;
use Throwable;

final readonly class DeployOperationRunner
{
    public function __construct(
        private DeployManager $deploy,
        private OperationRunRecorder $operationRuns,
        private OperationStreamFrameBroadcaster $broadcaster,
    ) {}

    /**
     * @return array{uuid: string, stream_descriptor_url: string, events_url: string}
     */
    public function start(string $app, Node $caller): array
    {
        $target = $this->deploy->runTarget($app);
        $node = $target->node;

        if (! $node instanceof Node) {
            throw new GatewayApiException(
                "App '{$target->name}' has no owning node.",
                'deploy.execution_failed',
                ['app' => $target->name],
            );
        }

        $operation = $this->operationRuns->queued(
            operationId: (string) Str::uuid(),
            lane: 'gateway',
            operationType: 'deploy.run',
            callerNodeId: $caller->id,
            targetNodeId: $node->id,
        );

        return [
            'uuid' => $operation->id,
            'stream_descriptor_url' => "/api/operations/{$operation->id}/stream",
            'events_url' => "/api/operations/{$operation->id}/events",
        ];
    }

    public function execute(string $operationRunId, string $app): void
    {
        $operation = OperationRun::query()->findOrFail($operationRunId);
        $this->operationRuns->running($operation->id);
        $reporter = new OperationStreamProgressReporter(
            operationRun: $operation,
            operationRuns: $this->operationRuns,
            broadcaster: $this->broadcaster,
        );

        try {
            $result = $this->deploy->run($app, detach: false, progress: $reporter);
            $data = ['run' => $result['run']];

            if (isset($result['output'])) {
                $data['output'] = $result['output'];
            }

            $data['footer'] = 'Deployment completed';
            $reporter->complete(0, $data);
            $this->operationRuns->succeeded($operation->id, result: $data);
        } catch (GatewayApiException $exception) {
            $error = [
                'code' => $exception->errorCode() ?? 'deploy.execution_failed',
                'message' => $exception->getMessage(),
                'meta' => $exception->errorMeta(),
                'data' => $exception->errorData(),
                'footer' => 'Deployment failed',
            ];
            $reporter->error($exception->getMessage(), 1, $error);
            $this->operationRuns->failed($operation->id, exitCode: 1, error: $error);
        } catch (Throwable $throwable) {
            $error = [
                'code' => 'deploy.execution_failed',
                'message' => $throwable->getMessage(),
                'meta' => [],
                'footer' => 'Deployment failed',
            ];
            $reporter->error($throwable->getMessage(), 1, $error);
            $this->operationRuns->failed($operation->id, exitCode: 1, error: $error);
        }
    }
}
