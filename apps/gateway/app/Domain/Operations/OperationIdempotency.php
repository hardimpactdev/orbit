<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** Request-scoped start identity, shared by the HTTP boundary and the operation store. */
final class OperationIdempotency
{
    public ?string $kind = null;

    public ?string $key = null;

    public ?string $fingerprint = null;

    public function replay(string $kind): ?Operation
    {
        if ($this->kind !== $kind || $this->key === null) {
            return null;
        }

        $operation = Operation::query()->where('kind', $kind)->where('idempotency_key', $this->key)->first();

        if ($operation !== null && $operation->idempotency_fingerprint !== $this->fingerprint) {
            throw new ResourceOperationException(
                'operation.idempotency_conflict',
                'This operation kind already has the Idempotency-Key with another target or input.',
                409,
            );
        }

        return $operation;
    }
}
