<?php

declare(strict_types=1);

namespace App\Spike\DurableFirewall;

use App\Domain\Executor\ExecutorOrderPublisher;
use App\Domain\Executor\ExecutorOrderSigner;
use App\Infrastructure\Executor\ExecutorResultQueue;
use App\Infrastructure\Executor\ExecutorResults;
use App\Models\Operation;
use RuntimeException;
use Workflow\V2\Activity;

/**
 * SPIKE: one typed primitive on one Node, over the observer's WebSocket to orbit-executor.
 *
 * Each workflow step owns one Operation, found again by its idempotency key. A retry after a lost worker signs a new
 * order for the same Operation, action and target, so the executor returns the stored result marked `repeat` instead
 * of acting twice. The activity only signs, publishes and waits; the Node does the work.
 */
final class ExecutorPrimitive extends Activity
{
    public ?string $connection = 'gateway';

    public ?string $queue = 'dw-activity';

    public int $tries = 3;

    private const float WaitSeconds = 30.0;

    /** @return array{status: string, stdout: string, repeat: bool, accepted_ms: ?int, result_ms: int} */
    public function handle(string $workflowId, string $step, int $nodeId, string $action, array $input = []): array
    {
        $operation = Operation::query()->firstOrCreate(
            ['idempotency_key' => "spike-primitive:{$workflowId}:{$step}"],
            ['kind' => 'spike.primitive', 'target_type' => 'node', 'target_id' => $nodeId, 'node_id' => $nodeId,
                'input' => ['action' => $action, 'target' => $step], 'status' => 'running', 'triggered_by' => 'spike'],
        );
        $started = hrtime(true);
        $order = ExecutorOrderSigner::fromConfig()->sign($operation, $action, $step, $input);
        app(ExecutorOrderPublisher::class)->publish($operation, $order);

        $queue = app(ExecutorResultQueue::class);
        $key = ExecutorResults::resultKey((int) $operation->id);
        $acceptedMs = null;
        $deadline = microtime(true) + self::WaitSeconds;
        while (($left = $deadline - microtime(true)) > 0.001) {
            $popped = $queue->pop([$key], min($left, 5.0));
            if ($popped === null) {
                continue;
            }
            $line = json_decode($popped[1], true, flags: JSON_THROW_ON_ERROR);
            $status = $line['status'] ?? null;
            if ($status === 'accepted') {
                $acceptedMs ??= intdiv(hrtime(true) - $started, 1_000_000);

                continue;
            }
            if ($status === 'refused' && ($line['reason'] ?? null) === 'replayed_nonce' && is_array($line['first'] ?? null)) {
                $line = $line['first'];
                $status = $line['status'] ?? null;
            }
            if ($status === 'output') {
                return ['status' => 'output', 'stdout' => (string) $line['stdout'], 'repeat' => (bool) ($line['repeat'] ?? false),
                    'accepted_ms' => $acceptedMs, 'result_ms' => intdiv(hrtime(true) - $started, 1_000_000)];
            }

            throw new RuntimeException("executor {$action} {$step}: ".($line['code'] ?? $line['reason'] ?? json_encode($line)));
        }

        throw new RuntimeException("executor {$action} {$step}: no result within ".self::WaitSeconds.' s');
    }
}
