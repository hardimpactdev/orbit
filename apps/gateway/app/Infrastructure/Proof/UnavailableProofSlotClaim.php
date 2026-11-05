<?php

declare(strict_types=1);

namespace App\Infrastructure\Proof;

use App\Domain\Proof\ProofSlot;
use App\Domain\Proof\ProofSlotClaim;
use App\Domain\Shared\ResourceOperationException;

/** The proof slot claim is not built yet, so no slot is ever free for a proof. Replace this binding with the claim. */
final readonly class UnavailableProofSlotClaim implements ProofSlotClaim
{
    public function claim(): ProofSlot
    {
        throw new ResourceOperationException('proof.no_slot', 'No task VM slot can run a proof yet: the proof slot claim is not built.', 409);
    }

    public function release(string $name): void {}
}
