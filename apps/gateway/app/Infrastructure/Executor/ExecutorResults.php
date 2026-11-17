<?php

declare(strict_types=1);

namespace App\Infrastructure\Executor;

use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The listener's side of the executor hand-off (ADR 0266 "The path", step 4). The agent view subscriber passes
 * each checked reply line here. A result is recorded on its Operation first, so it is durable (D7), and then
 * pushed to the Valkey list `executor:op:<operation>` for a waiting request (`ExecutorResultWait`). The list
 * holds the owning Node's `accepted` and result lines in arrival order and expires after `ExpirySeconds`. No queue job, HTTP call or Laravel boot sits between the two sides.
 */
final readonly class ExecutorResults
{
    public const string KeyPrefix = 'executor:op:';

    public const int ExpirySeconds = 60;

    /** The Problem collector selects Activity codes that end in `_failed` (docs/reference/tasks.md#fingerprints). */
    public const string RefusalCode = 'executor.order_check_failed';

    public function __construct(
        private ExecutorResultQueue $queue,
        private LoggerInterface $log,
        private CommandActivityInputSanitizer $sanitizer,
    ) {}

    public static function resultKey(int $operation): string
    {
        return self::KeyPrefix.$operation;
    }

    /** Hands one reply line from Node `$nodeId`'s agent to the waiting request. Throws when Valkey or the database fails. */
    public function receive(int $nodeId, ExecutorReply $reply): void
    {
        if (! $this->verifyAndRecord($nodeId, $reply)) {
            $this->log->warning('The agent view subscriber dropped an executor reply for an Operation that is not on its Node.', [
                'node_id' => $nodeId, 'operation_id' => $reply->operation, 'status' => $reply->status,
            ]);

            return;
        }

        // Reported before the push, so a Valkey failure never hides a refusal.
        if ($reply->status === 'refused') {
            $this->refused($nodeId, $reply);
        } elseif ($reply->status === 'failed') {
            $this->log->warning('The executor reported a failed order.', ['node_id' => $nodeId, 'operation_id' => $reply->operation, 'code' => $reply->reason]);
        }

        $this->queue->push(self::resultKey($reply->operation), $reply->raw, self::ExpirySeconds);
    }

    /**
     * Checks that the reply comes from the Operation's owning Node. For a terminal reply, it stores the first result.
     * A copy of a recorded result still goes to Valkey to recover a lost push.
     */
    private function verifyAndRecord(int $nodeId, ExecutorReply $reply): bool
    {
        $operation = Operation::query()->whereKey($reply->operation)->where('node_id', $nodeId);

        if (! $reply->isAccepted() && (clone $operation)->whereNull('executor_result')->update(['executor_result' => $reply->raw]) === 1) {
            return true;
        }

        return $operation->exists();
    }

    /**
     * Logs a refusal with its reason and leaves a failed Activity that the Problem collector turns into a Problem, the
     * Gateway's alert path. The row is saved without its model observers, because the broadcast observer sends a
     * Reverb HTTP request at once and the socket loop must not wait for it. The properties sanitizer still runs.
     */
    private function refused(int $nodeId, ExecutorReply $reply): void
    {
        $context = ['node_id' => $nodeId, 'operation_id' => $reply->operation, 'reason' => $reply->reason,
            'node_clock_ms' => is_int($reply->line['node_clock_ms'] ?? null) ? $reply->line['node_clock_ms'] : null];
        $this->log->warning('The executor refused an order.', $context);

        try {
            $activity = new Activity(['log_name' => 'executor', 'description' => 'executor.order_refused',
                'subject_type' => Node::class, 'subject_id' => $nodeId, 'command' => 'orbit:agent-view',
                'request_id' => (string) Str::uuid(), 'status' => 'failed', 'error_code' => self::RefusalCode,
                'properties' => $this->sanitizer->sanitizeProperties([...$context,
                    'error_message' => sprintf('The executor on Node %d refused the order of Operation %d: %s.', $nodeId, $reply->operation, $reply->reason ?? 'unknown')])]);
            Activity::withoutEvents(static fn (): bool => $activity->save());
        } catch (Throwable $exception) {
            $this->log->warning('The agent view subscriber could not record an executor refusal.', ['node_id' => $nodeId, 'error' => $exception->getMessage()]);
        }
    }
}
