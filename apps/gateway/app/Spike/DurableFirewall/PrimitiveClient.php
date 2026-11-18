<?php

declare(strict_types=1);

namespace App\Spike\DurableFirewall;

use App\Domain\Executor\ExecutorOrderPublisher;
use App\Domain\Executor\ExecutorOrderSigner;
use App\Infrastructure\Executor\ExecutorResults;
use App\Infrastructure\Executor\ValkeyExecutorResultQueue;
use App\Models\Operation;
use RuntimeException;

/**
 * SPIKE: runs one typed primitive on one Node over the observer's WebSocket to orbit-executor and waits for its result.
 *
 * `$stepKey` names the step for good: the same key finds the same Operation again, so sending it twice makes the
 * executor return the stored result marked `repeat` instead of acting twice. Both the Durable Workflow activity and the
 * plain job use this one client.
 */
final class PrimitiveClient
{
    private const float WaitSeconds = 10.0;

    /** @return array{status: string, stdout: string, repeat: bool, accepted_ms: ?int, result_ms: int} */
    public static function call(string $stepKey, string $step, int $nodeId, string $action, array $input = []): array
    {
        $operation = Operation::query()->firstOrCreate(
            ['idempotency_key' => "spike-primitive:{$stepKey}"],
            ['kind' => 'spike.primitive', 'target_type' => 'node', 'target_id' => $nodeId, 'node_id' => $nodeId,
                'input' => ['action' => $action, 'target' => $step], 'status' => 'running', 'triggered_by' => 'spike'],
        );
        $started = hrtime(true);
        $order = ExecutorOrderSigner::fromConfig()->sign($operation, $action, $step, $input);
        app(ExecutorOrderPublisher::class)->publish($operation, $order);

        $queue = app(ValkeyExecutorResultQueue::class);
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
            if ($status === 'output' || $status === 'done') {
                $stdout = (string) ($line['stdout'] ?? '');
                if (($line['encoding'] ?? null) === 'gzip+base64') {
                    $stdout = (string) gzdecode((string) base64_decode($stdout, true));
                }

                return ['status' => $status, 'stdout' => $stdout, 'repeat' => (bool) ($line['repeat'] ?? false),
                    'accepted_ms' => $acceptedMs, 'result_ms' => intdiv(hrtime(true) - $started, 1_000_000)];
            }

            throw new RuntimeException("executor {$action} {$step}: ".($line['code'] ?? $line['reason'] ?? json_encode($line)));
        }

        throw new RuntimeException("executor {$action} {$step}: no result within ".self::WaitSeconds.' s');
    }
}
