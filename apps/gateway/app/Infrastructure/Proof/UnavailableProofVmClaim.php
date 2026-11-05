<?php

declare(strict_types=1);

namespace App\Infrastructure\Proof;

use App\Domain\Proof\ProofVm;
use App\Domain\Proof\ProofVmClaim;
use App\Domain\Shared\ResourceOperationException;

/** The proof VM claim is not built yet, so no VM is ever free for a proof. Replace this binding with the claim. */
final readonly class UnavailableProofVmClaim implements ProofVmClaim
{
    public function claim(): ProofVm
    {
        throw new ResourceOperationException('proof.no_vm', 'No VM can run a proof yet: the proof VM claim is not built.', 409);
    }

    public function release(string $name): void {}
}
