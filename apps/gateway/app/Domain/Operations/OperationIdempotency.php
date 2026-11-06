<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Closure;

/** Request-scoped start identity, shared by the HTTP boundary and the operation store. */
final class OperationIdempotency
{
    public ?string $kind = null;

    public ?string $key = null;

    public ?string $fingerprint = null;

    /**
     * Runs the callback under another start identity and puts the previous one back, also when it throws. The HTTP boundary does
     * it for the request header; an automatic caller does it to key its own admissions.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function under(string $kind, string $key, string $fingerprint, Closure $callback): mixed
    {
        return $this->swap($kind, $key, $fingerprint, $callback);
    }

    /**
     * Runs the callback with no start identity and puts the previous one back, also when it throws. An admission that the request
     * did not ask for, such as the resume of a parked VM that a turn needs, must not take the key of the request.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function suspended(Closure $callback): mixed
    {
        return $this->swap(null, null, null, $callback);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function swap(?string $kind, ?string $key, ?string $fingerprint, Closure $callback): mixed
    {
        $previous = [$this->kind, $this->key, $this->fingerprint];
        [$this->kind, $this->key, $this->fingerprint] = [$kind, $key, $fingerprint];

        try {
            return $callback();
        } finally {
            [$this->kind, $this->key, $this->fingerprint] = $previous;
        }
    }

    /**
     * Returns the recorded intent without resubmitting its native workflow.
     *
     * @phpstan-impure
     */
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
