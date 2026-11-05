<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Shared\ResourceOperationException;

/**
 * Takes a VM for one proof, in proof mode: no Pi token, no model key and no route to the real Gateway. The VM claim is
 * not built yet. The proof run only asks for a VM and holds it until its terminal result.
 */
interface ProofVmClaim
{
    /** @throws ResourceOperationException proof.no_vm */
    public function claim(): ProofVm;

    /**
     * Frees the VM after the run ends, however it ended. The claim also frees a VM whose Operation ended with no
     * projection, such as one cancelled before it started.
     */
    public function release(string $name): void;
}
