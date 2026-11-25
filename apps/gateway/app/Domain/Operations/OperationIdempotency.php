<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Closure;
use Illuminate\Support\Facades\DB;

/** Request-scoped start identity, shared by the HTTP boundary and the operation store. */
final class OperationIdempotency
{
    private const string Aliases = 'operation_idempotency_aliases';

    public ?string $kind = null;

    public ?string $key = null;

    public ?string $fingerprint = null;

    private ?Operation $parent = null;

    public function parent(string $kind): ?Operation
    {
        return $this->kind === $kind && $this->key !== null ? $this->parent : null;
    }

    /**
     * Runs the callback under another start identity and puts the previous one back, also when it throws. The HTTP boundary does
     * it for the request header; an automatic caller does it to key its own admissions.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function under(string $kind, string $key, string $fingerprint, Closure $callback, ?Operation $parent = null): mixed
    {
        return $this->swap($kind, $key, $fingerprint, $callback, $parent);
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
    private function swap(?string $kind, ?string $key, ?string $fingerprint, Closure $callback, ?Operation $parent = null): mixed
    {
        $previous = [$this->kind, $this->key, $this->fingerprint, $this->parent];
        [$this->kind, $this->key, $this->fingerprint, $this->parent] = [$kind, $key, $fingerprint, $parent];

        try {
            return $callback();
        } finally {
            [$this->kind, $this->key, $this->fingerprint, $this->parent] = $previous;
        }
    }

    /** Binds a caller's key to the returned receipt inside the same admission transaction. */
    public function bind(Operation $operation): Operation
    {
        if ($this->kind === $operation->kind && $this->key !== null && $this->key !== $operation->idempotency_key) {
            DB::table(self::Aliases)->insert([
                'kind' => $this->kind, 'key' => $this->key, 'fingerprint' => $this->fingerprint, 'operation_id' => $operation->id,
            ]);
        }

        return $operation;
    }

    /**
     * Returns the recorded intent without resubmitting its native workflow.
     *
     * @phpstan-impure
     */
    public function replay(string $kind, ?Operation $parent = null): ?Operation
    {
        if ($this->kind !== $kind || $this->key === null) {
            return null;
        }

        $operation = Operation::query()->where('kind', $kind)->where('idempotency_key', $this->key)->first();
        $fingerprint = $operation?->idempotency_fingerprint;
        if ($operation === null) {
            $alias = DB::table(self::Aliases)->where('kind', $kind)->where('key', $this->key)->first();
            if ($alias !== null) {
                $operation = Operation::query()->whereKey($alias->operation_id)->firstOrFail();
                $fingerprint = $alias->fingerprint;
            }
        }

        $parent ??= $this->parent($kind);
        $owner = $operation->parent_operation_id ?? $operation->state['conn_launch_owner'] ?? $operation->input['launch_operation_id'] ?? $operation->input['proof_operation_id'] ?? null;
        if ($operation !== null && ($fingerprint !== $this->fingerprint || ($parent !== null && $owner !== $parent->id))) {
            throw new ResourceOperationException(
                'operation.idempotency_conflict',
                'This operation kind already has the Idempotency-Key with another target or input.',
                409,
            );
        }

        return $operation;
    }
}
