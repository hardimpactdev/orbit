<?php

declare(strict_types=1);

namespace App\Infrastructure\Executor;

use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The listener's side of the executor hand-off (ADR 0266 "The path", step 4). The agent view subscriber passes
 * each checked reply line here. A result is recorded on its Operation first, so it is durable (D7), and then
 * pushed to the Valkey key `executor:op:<operation>` for a waiting request (`ExecutorResultWait`). The `accepted`
 * line goes only to `executor:op:<operation>:accepted`, so a waiter can tell it from the result. Both keys
 * expire after `ExpirySeconds`. No queue job, HTTP call or Laravel boot sits between the two sides.
 */
final readonly class ExecutorResults
{
    public const string KeyPrefix = 'executor:op:';

    public const int ExpirySeconds = 60;

    public function __construct(private ExecutorResultQueue $queue, private LoggerInterface $log) {}

    public static function resultKey(int $operation): string
    {
        return self::KeyPrefix.$operation;
    }

    public static function acceptedKey(int $operation): string
    {
        return self::KeyPrefix.$operation.':accepted';
    }

    /** Hands one reply line from Node `$nodeId`'s agent to the waiting request. Throws when Valkey or the database fails. */
    public function receive(int $nodeId, ExecutorReply $reply): void
    {
        if ($reply->isAccepted()) {
            $this->queue->push(self::acceptedKey($reply->operation), $reply->raw, self::ExpirySeconds);

            return;
        }

        if (! $this->record($nodeId, $reply)) {
            $this->log->warning('The agent view subscriber dropped an executor result for an Operation that is not on its Node.', [
                'node_id' => $nodeId, 'operation_id' => $reply->operation, 'status' => $reply->status,
            ]);

            return;
        }

        $this->queue->push(self::resultKey($reply->operation), $reply->raw, self::ExpirySeconds);

        if ($reply->status === 'refused') {
            $this->refused($nodeId, $reply);
        } elseif ($reply->status === 'failed') {
            $this->log->warning('The executor reported a failed order.', ['node_id' => $nodeId, 'operation_id' => $reply->operation, 'code' => $reply->reason]);
        }
    }

    /**
     * One conditional write: it stores the result only on an Operation of this Node that has none yet, so a second
     * copy of a result (a repeat, or two listeners) changes no row. False when the Operation is not on this Node.
     */
    private function record(int $nodeId, ExecutorReply $reply): bool
    {
        $changed = Operation::query()->whereKey($reply->operation)->where('node_id', $nodeId)->whereNull('executor_result')
            ->update(['executor_result' => $reply->raw]);

        // A copy of a recorded result still goes to Valkey: it may be the one that recovers a lost push.
        return $changed === 1 || Operation::query()->whereKey($reply->operation)->where('node_id', $nodeId)->exists();
    }

    /** Logs a refusal with its reason and leaves an Activity problem, the Gateway's alert path for a Node. */
    private function refused(int $nodeId, ExecutorReply $reply): void
    {
        $context = ['node_id' => $nodeId, 'operation_id' => $reply->operation, 'reason' => $reply->reason,
            'node_clock_ms' => is_int($reply->line['node_clock_ms'] ?? null) ? $reply->line['node_clock_ms'] : null];
        $this->log->warning('The executor refused an order.', $context);

        try {
            Activity::query()->create(['log_name' => 'executor', 'description' => 'executor.order_refused',
                'subject_type' => Node::class, 'subject_id' => $nodeId, 'command' => 'orbit:agent-view',
                'request_id' => (string) Str::uuid(), 'status' => 'failed', 'error_code' => 'executor.order_refused',
                'properties' => $context]);
        } catch (Throwable $exception) {
            $this->log->warning('The agent view subscriber could not record an executor refusal.', ['node_id' => $nodeId, 'error' => $exception->getMessage()]);
        }
    }
}
