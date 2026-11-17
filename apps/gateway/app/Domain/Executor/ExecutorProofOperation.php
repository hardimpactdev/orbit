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
 * durable Operation row on its Node, so the listener records its result like any other (D7). It sends `proof.noop`, or sleep, wake or discard on an Instance, as
 * `executor-order` and waits for the result with {@see ExecutorResultWait}, the waiting request's path (D5). It starts no Swamp run.
 * The no-op changes nothing; the power proof hibernates the whole target.
 *
 * The Node diagnostic calls diagnose() for one no-op. The nested proof runner also uses the proof lifecycle directly,
 * including the power proof.
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

    public function diagnose(Node $node, string $requestId): Operation
    {
        $operation = $this->start($node, null, self::Action, $requestId);
        if ($operation->status->terminal()) {
            return $operation;
        }

        try {
            $this->send($operation, ExecutorOrderSigner::fromConfig());
            $result = $this->wait($operation);
        } catch (Throwable $exception) {
            $this->abort($operation);
            report($exception);

            return $operation;
        }

        $this->finish($operation, $result);

        return $operation;
    }

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
        if ($instance !== null && (! in_array($action, ['sleep', 'wake', 'discard'], true) || $instance->node_id !== $node->id)) {
            throw new InvalidArgumentException('Power proof needs a sleep, wake or discard target on its Node.');
        }
        $started = [];

        try {
            return $body(function () use ($node, $instance, $action, &$started): Operation {
                return $started[] = $this->start($node, $instance, $action, (string) Str::uuid());
            });
        } catch (Throwable $exception) {
            foreach ($started as $operation) {
                $this->abort($operation);
            }

            throw $exception;
        }
    }

    /** Records a proof Operation on its Node or Instance target. */
    private function start(Node $node, ?Instance $instance, string $action, string $requestId): Operation
    {
        $operation = $this->operations->create($instance === null ? OperationKinds::ExecutorProof : OperationKinds::ExecutorPowerProof, $instance === null ? 'node' : 'instance', $instance === null ? $node->id : $instance->id, $node->id,
            ['action' => $instance === null ? self::Action : $action], self::TriggeredBy, $requestId);
        $this->operations->change($operation, ['status' => OperationStatus::Running, 'started_at' => now()], onlyWhileOpen: true);

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
        $action = $operation->input['action'] ?? self::Action;
        if (! is_string($action)) {
            throw new InvalidArgumentException('An executor proof Operation needs a string action.');
        }
        $order = $signer->sign($operation, $action, (string) $operation->target_id);
        $this->publisher->publish($operation, $order);

        return $order;
    }

    /** Waits for the Operation's result as a waiting request does, within `$boundSeconds` or `ORBIT_EXECUTOR_WAIT_MS`. */
    public function wait(Operation $operation, ?float $boundSeconds = null): ExecutorWaitResult
    {
        return $this->wait->wait($operation->id, $boundSeconds);
    }

    public function finish(Operation $operation, ExecutorWaitResult $result): void
    {
        $error = $this->proofError($operation, $result);
        $this->operations->change($operation, $error === null
            ? ['status' => OperationStatus::Succeeded, 'finished_at' => now(), 'result' => $result->line]
            : ['status' => OperationStatus::Failed, 'finished_at' => now(), 'error_code' => $error], onlyWhileOpen: true);
    }

    private function abort(Operation $operation): void
    {
        $this->operations->change($operation, ['status' => OperationStatus::Failed, 'finished_at' => now(), 'error_code' => self::Aborted], onlyWhileOpen: true);
    }

    private function proofError(Operation $operation, ExecutorWaitResult $result): ?string
    {
        if ($operation->kind === OperationKinds::ExecutorPowerProof && $result->status === ExecutorWaitStatus::Partial) {
            return null;
        }

        if ($result->status !== ExecutorWaitStatus::Done) {
            return 'executor.proof_'.$result->status->value;
        }

        if ($operation->kind !== OperationKinds::ExecutorProof) {
            return null;
        }

        if ($result->acceptedMs === null) {
            return 'executor.proof_not_accepted';
        }

        return ($result->line['operation'] ?? null) === (string) $operation->id
            && ($result->line['target'] ?? null) === (string) $operation->target_id
            && ($result->line['action'] ?? null) === self::Action
            ? null
            : 'executor.proof_result_mismatch';
    }
}
