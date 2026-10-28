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

    public ?Operation $parent = null;

    public ?string $childKey = null;

    /** @var array{key:string,kind:string,node_id:int|null,input:array<string,mixed>}|null */
    public ?array $childIntent = null;

    /** @param array{key:string,kind:string,node_id:int|null,input:array<string,mixed>} $intent
     * @param  Closure():Operation  $admit
     */
    public function duringChild(Operation $parent, array $intent, Closure $admit): Operation
    {
        $before = [$this->kind, $this->key, $this->fingerprint, $this->parent, $this->childKey, $this->childIntent];
        $this->kind = $intent['kind'];
        $this->key = 'child-'.$parent->id.'.'.hash('sha256', $intent['key']);
        $this->fingerprint = OperationChildIntent::fingerprint($intent);
        $this->parent = $parent;
        $this->childKey = $intent['key'];
        $this->childIntent = $intent;
        try {
            return $admit();
        } finally {
            [$this->kind, $this->key, $this->fingerprint, $this->parent, $this->childKey, $this->childIntent] = $before;
        }
    }

    /**
     * Reads the current winner; another start can record it between reads.
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
