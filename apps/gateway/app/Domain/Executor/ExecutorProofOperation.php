<?php

declare(strict_types=1);

namespace App\Domain\Executor;

use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Infrastructure\Executor\ExecutorResultWait;
use App\Infrastructure\Executor\ExecutorWaitResult;
use App\Infrastructure\Executor\ExecutorWaitStatus;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use Closure;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * The proof-only Operation kinds `executor.proof` and `executor.power_proof` (docs/reference/executor.md, ADR 0266 slices 3/4). Each is a
 * durable Operation row on its Node, so the listener records its result like any other (D7). It sends `proof.noop`, or sleep/discard on an Instance, as
 * `executor-order` and waits for the result with {@see ExecutorResultWait}, the waiting request's path (D5). It starts no Swamp run.
 * The no-op changes nothing; the power proof hibernates the whole target.
 *
 * No route, CLI command, job or schedule names this kind: only the proof support on a nested Gateway
 * (`tools/swamp-proof-guest/executor-order.php`, run as that Gateway's managed user) calls this class.
 */
final readonly class ExecutorProofOperation
{
    public const string Action = 'proof.noop';

    public const string TriggeredBy = 'executor-proof';

    /** The error code of a proof Operation whose run threw before it could close it with its result. */
    public const string Aborted = 'executor.proof_aborted';

    public function __construct(
        private OperationStore $operations,
        private ExecutorOrderPublisher $publisher,
        private ExecutorResultWait $wait,
    ) {}

    /**
     * Runs one proof run, `$body`, which records its Operations through the `start` closure it gets. When `$body` throws, every
     * Operation it recorded that is still open closes `failed` with {@see self::Aborted}, so no proof Operation stays running.
     *
     * @template T
     *
     * @param  Closure(Closure(): Operation): T  $body
     * @return T
     */
    public function run(Node $node, Closure $body, ?Instance $instance = null, string $action = self::Action): mixed
    {
        if ($instance !== null && (! in_array($action, ['sleep', 'discard'], true) || $instance->node_id !== $node->id)) {
            throw new InvalidArgumentException('Power proof needs a sleep/discard target on its Node.');
        }
        $started = [];

        try {
            return $body(function () use ($node, $instance, $action, &$started): Operation {
                return $started[] = $this->start($node, $instance, $action);
            });
        } catch (Throwable $exception) {
            foreach ($started as $operation) {
                if (! $operation->refresh()->status->terminal()) {
                    $this->operations->change($operation, ['status' => OperationStatus::Failed, 'finished_at' => now(), 'error_code' => self::Aborted]);
                }
            }

            throw $exception;
        }
    }

    /** Records a running proof Operation on its Node or Instance target. Only {@see self::run} calls it. */
    private function start(Node $node, ?Instance $instance, string $action): Operation
    {
        $operation = $this->operations->create($instance === null ? OperationKinds::ExecutorProof : OperationKinds::ExecutorPowerProof, $instance === null ? 'node' : 'instance', $instance?->id ?? $node->id, $node->id,
            ['action' => $instance === null ? self::Action : $action], self::TriggeredBy, (string) Str::uuid());
        $this->operations->change($operation, ['status' => OperationStatus::Running, 'started_at' => now()]);

        return $operation;
    }

    /**
     * Signs a new order, with a new nonce, for the Operation and publishes it. For an Operation that already ran, the executor
     * answers `repeat` with the first result and the listener pushes it again: this recovers a lost result.
     *
     * @throws InvalidArgumentException when the Operation is not an open `executor.proof`.
     */
    public function send(Operation $operation, ExecutorOrderSigner $signer): SignedExecutorOrder
    {
        if (! in_array($operation->kind, [OperationKinds::ExecutorProof, OperationKinds::ExecutorPowerProof], true) || $operation->status->terminal()) {
            throw new InvalidArgumentException('Only an open executor proof Operation sends a proof order.');
        }
        $order = $signer->sign($operation, (string) ($operation->input['action'] ?? self::Action), (string) $operation->target_id);
        $this->publisher->publish($operation, $order);

        return $order;
    }

    /** Waits for the Operation's result as a waiting request does, within `$boundSeconds` or `ORBIT_EXECUTOR_WAIT_MS`. */
    public function wait(Operation $operation, ?float $boundSeconds = null): ExecutorWaitResult
    {
        return $this->wait->wait($operation->id, $boundSeconds);
    }

    /** Closes the Operation: succeeded on a `done` result, failed with `executor.proof_<status>` otherwise. */
    public function finish(Operation $operation, ExecutorWaitResult $result): void
    {
        $this->operations->change($operation, $result->status === ExecutorWaitStatus::Done
            ? ['status' => OperationStatus::Succeeded, 'finished_at' => now(), 'result' => $result->line]
            : ['status' => OperationStatus::Failed, 'finished_at' => now(), 'error_code' => 'executor.proof_'.$result->status->value]);
    }
}
