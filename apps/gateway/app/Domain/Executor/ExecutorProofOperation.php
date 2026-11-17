<?php

declare(strict_types=1);

namespace App\Domain\Executor;

use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Infrastructure\Executor\ExecutorResultWait;
use App\Infrastructure\Executor\ExecutorWaitResult;
use App\Infrastructure\Executor\ExecutorWaitStatus;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The proof-only Operation kind `executor.proof` (docs/reference/executor.md#the-proof-operation, ADR 0266 slice 3). Each one is a
 * durable Operation row on its Node, so the listener records its result like any other (D7). It sends the no-op `proof.noop` as
 * `executor-order` and waits for the result with {@see ExecutorResultWait}, the waiting request's path (D5). It starts no Swamp run
 * and changes nothing on the Node.
 *
 * No route, CLI command, job or schedule names this kind: only the proof support on a nested Gateway
 * (`tools/swamp-proof-guest/executor-order.php`, run as that Gateway's managed user) calls this class.
 */
final readonly class ExecutorProofOperation
{
    public const string Action = 'proof.noop';

    public const string TriggeredBy = 'executor-proof';

    public function __construct(
        private OperationStore $operations,
        private ExecutorOrderPublisher $publisher,
        private ExecutorResultWait $wait,
    ) {}

    /** Records a running `executor.proof` Operation on the Node; its target is the Node too, the target of `proof.noop`. */
    public function start(Node $node): Operation
    {
        $operation = $this->operations->create(OperationKinds::ExecutorProof, 'node', $node->id, $node->id,
            ['action' => self::Action], self::TriggeredBy, (string) Str::uuid());
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
        if ($operation->kind !== OperationKinds::ExecutorProof || $operation->status->terminal()) {
            throw new InvalidArgumentException('Only an open executor.proof Operation sends a proof order.');
        }
        $order = $signer->sign($operation, self::Action, (string) ExecutorOrderSigner::nodeOf($operation));
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
