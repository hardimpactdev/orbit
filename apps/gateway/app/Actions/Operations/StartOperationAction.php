<?php

declare(strict_types=1);

namespace App\Actions\Operations;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationDispatcher;
use App\Domain\Operations\OperationExecutors;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationRunner;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Throwable;

/**
 * Creates a queued operation that holds its target and queues its job (docs/reference/operations.md#start-an-operation).
 * The caller validates the kind's own input first.
 */
final readonly class StartOperationAction
{
    public function __construct(
        private OperationStore $store,
        private OperationDispatcher $dispatcher,
        private OperationRunner $runner,
        private OperationIdempotency $idempotency,
    ) {}

    /** Refuses before anything is recorded when the Gateway has no queue. */
    public function assertAvailable(): void
    {
        if (! $this->dispatcher->available()) {
            throw new ResourceOperationException(
                'operation.queue_unavailable',
                'Operations run on the Gateway queue, but ORBIT_GATEWAY_QUEUE_CONNECTION is empty. See /reference/gateway-queue.',
                503,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $supersedes  the kinds of a queued operation on the target that this one cancels and replaces
     */
    public function execute(string $kind, string $targetType, int $targetId, ?int $nodeId, array $input, ?string $triggeredBy, ?string $requestId, array $supersedes = []): Operation
    {
        if (($existing = $this->idempotency->replay($kind)) !== null) {
            return $existing;
        }

        if (! app(OperationExecutors::class)->swamp($kind)) {
            $this->assertAvailable();
        }
        $operation = $this->store->create($kind, $targetType, $targetId, $nodeId, $input, $triggeredBy, $requestId, $supersedes);

        if (! $operation->wasRecentlyCreated) {
            return $operation;
        }

        if (DirectOperation::owns($operation)) {
            $this->dispatcher->dispatch($operation);

            return $operation;
        }

        try {
            $this->dispatcher->dispatch($operation);
        } catch (Throwable $exception) {
            $this->runner->finishWithoutRun($operation, OperationOutcome::failed('operation.dispatch_failed'));

            throw new ResourceOperationException(
                'operation.dispatch_failed',
                'The Gateway could not queue the operation. Check the Gateway queue and start it again.',
                503,
                $exception,
                ['operation_id' => $operation->id],
            );
        }

        return $operation;
    }
}
