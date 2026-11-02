<?php

declare(strict_types=1);

namespace App\Actions\Operations;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Models\Operation;

/** Records validated intent, then submits its single native workflow after commit, or records a no-run success (R1). */
final readonly class StartOperationAction
{
    public function __construct(private OperationStore $store, private OperationIdempotency $idempotency) {}

    /** @param array<string,mixed> $input
     * @param  list<string>  $supersedes
     */
    public function execute(string $kind, string $targetType, int $targetId, ?int $nodeId, array $input, ?string $triggeredBy, ?string $requestId, array $supersedes = [], ?string $expectedWriter = null, ?string $lockKey = null): Operation
    {
        app(DirectOperation::class)->writer($kind);
        if (($existing = $this->idempotency->replay($kind)) !== null) {
            return $existing;
        }
        $operation = $this->store->create($kind, $targetType, $targetId, $nodeId, $input, $triggeredBy, $requestId, $lockKey);
        if ($operation->wasRecentlyCreated) {
            app(SwampDispatch::class)->start($operation);
        }

        return $operation;
    }

    /**
     * R1: validated intent that needs no Node work becomes a succeeded Operation at once, with no native run.
     * The caller writes the record change in the same transaction.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $result
     */
    public function record(string $kind, string $targetType, int $targetId, int $nodeId, array $input, array $result, ?string $triggeredBy, ?string $requestId, ?string $lockKey = null): Operation
    {
        app(DirectOperation::class)->writer($kind);
        if (($existing = $this->idempotency->replay($kind)) !== null) {
            return $existing;
        }
        // The lock key refuses a busy target, as a run would; the success releases it at once.
        $operation = $this->store->create($kind, $targetType, $targetId, $nodeId, $input, $triggeredBy, $requestId, $lockKey);
        if ($operation->wasRecentlyCreated) {
            $this->store->change($operation, ['status' => OperationStatus::Succeeded, 'result' => $result, 'started_at' => now(), 'finished_at' => now(), 'lock_key' => null]);
        }

        return $operation;
    }
}
