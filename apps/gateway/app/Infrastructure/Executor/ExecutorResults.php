<?php

declare(strict_types=1);

namespace App\Infrastructure\Executor;

use App\Domain\Instances\Power\ExecutorFence;
use App\Domain\Instances\Power\ExecutorPowerIntent;
use App\Domain\Instances\Power\ExecutorWake;
use App\Domain\Instances\Power\InstanceWake;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Jobs\Operations\CompleteExecutorWake;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The listener's side of the executor hand-off (ADR 0266 "The path", step 4). The agent view subscriber passes
 * each checked reply line here. A result is recorded on its Operation first, so it is durable (D7), and then
 * pushed to the Valkey list `executor:op:<operation>` for a waiting request (`ExecutorResultWait`). The list
 * holds the owning Node's `accepted` and result lines in arrival order and expires after `ExpirySeconds`.
 * A first signed wake result also queues completion for callers outside the request bound.
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
    public function receive(int $nodeId, ExecutorReply $reply, string $route = 'websocket'): void
    {
        $promptCompletion = $this->verifyAndRecord($nodeId, $reply, $route);
        if ($promptCompletion === null) {
            $this->log->warning('The agent view subscriber dropped an executor reply for an Operation that is not on its Node.', [
                'node_id' => $nodeId, 'operation_id' => $reply->operation, 'status' => $reply->status,
            ]);

            return;
        }

        if ($reply->status === 'refused' && ! $reply->isReplay()) {
            $this->refused($nodeId, $reply);
        } elseif ($reply->status === 'failed') {
            $this->log->warning('The executor reported a failed order.', ['node_id' => $nodeId, 'operation_id' => $reply->operation, 'code' => $reply->reason]);
        }

        if ($route !== 'websocket') {
            return;
        }

        try {
            $this->queue->push(self::resultKey($reply->operation), $reply->raw, self::ExpirySeconds);
        } finally {
            if ($promptCompletion) {
                try {
                    Bus::dispatch((new CompleteExecutorWake($reply->operation))->afterCommit());
                } catch (Throwable $exception) {
                    $this->log->warning('The recorded executor wake could not be queued for prompt completion.', [
                        'operation_id' => $reply->operation, 'exception_type' => $exception::class,
                    ]);
                }
            }
        }
    }

    /**
     * A replay envelope carries transport evidence, not a result in its own right. Its matching first outcome can recover a lost
     * original; the original wire lines still go to the ordered list.
     * Null rejects a line, true also prompts wake completion, and false only hands the line to the waiter.
     */
    private function verifyAndRecord(int $nodeId, ExecutorReply $reply, string $route): ?bool
    {
        $owner = Operation::query()->whereKey($reply->operation)->where('node_id', $nodeId);
        $operation = (clone $owner)->first();
        if ($operation === null) {
            return null;
        }
        $action = match (true) {
            ExecutorWake::owns($operation) => ExecutorPowerIntent::action($operation) ?? 'wake',
            $operation->kind === OperationKinds::ExecutorProof && $operation->target_type === 'node' && $operation->target_id === $nodeId => 'proof.noop',
            $operation->kind === OperationKinds::ExecutorPowerProof && $operation->target_type === 'instance' => $operation->input['action'] ?? null,
            default => null,
        };
        if (! in_array($action, ['proof.noop', 'sleep', 'wake', 'discard'], true)) {
            return null;
        }
        if ($reply->isAccepted()) {
            if ($route === 'websocket' && ExecutorPowerIntent::managed($operation)) {
                $owner->whereNull('executor_accepted_us')->update(['executor_accepted_us' => (int) now()->format('Uu')]);
            }

            return false;
        }
        $result = $reply->isReplay() ? $reply->first() : $reply;
        if ($result === null) {
            return ($reply->line['first'] ?? null) === null ? false : null;
        }
        $line = $result->line;
        if (! self::matches($operation, $action, $line)) {
            return null;
        }
        $managed = ExecutorPowerIntent::managed($operation);
        $line = $managed ? [...$line, 'delivery' => $route] : $line;
        $recorded = $owner->whereNull('executor_result')->update(['executor_result' => json_encode($line, JSON_THROW_ON_ERROR), 'executor_result_received_us' => (int) now()->format('Uu')]);
        if ($recorded === 1 && $managed) {
            ExecutorFence::resolve($operation);
        }

        return $recorded === 1 && $managed && $route === 'websocket' && $operation->kind === InstanceWake::Kind
            && $operation->status === OperationStatus::Running;
    }

    /** @param array<string, mixed> $line */
    public static function matches(Operation $operation, string $action, array $line): bool
    {
        $status = $line['status'] ?? null;
        $refused = $status === 'refused';
        $id = (string) $operation->id;
        $target = (string) $operation->target_id;

        return in_array($status, ['done', 'partial', 'failed', 'refused'], true)
            && ($line['operation'] ?? ($refused ? $id : null)) === $id
            && ($line['action'] ?? ($refused ? $action : null)) === $action
            && ($line['target'] ?? ($refused ? $target : null)) === $target;
    }

    /** @return array<string, mixed>|null */
    public static function matchingReceipt(Operation $operation, string $action, mixed $receipt): ?array
    {
        try {
            $line = ValidatedData::object($receipt);
        } catch (InvalidArgumentException) {
            return null;
        }

        return self::matches($operation, $action, $line) ? $line : null;
    }

    /** @param array<string, mixed>|null $line */
    public static function succeeded(?array $line): bool
    {
        return in_array($line['status'] ?? null, ['done', 'partial'], true);
    }

    /** @param array<string, mixed> $line */
    public static function failureCode(array $line): string
    {
        return match (true) {
            ($line['status'] ?? null) === 'refused' => 'executor.order_refused',
            is_string($line['code'] ?? null) => $line['code'],
            default => 'executor.power_failed',
        };
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
            $this->log->warning('The agent view subscriber could not record an executor refusal.', ['node_id' => $nodeId, 'exception_type' => $exception::class]);
        }
    }
}
