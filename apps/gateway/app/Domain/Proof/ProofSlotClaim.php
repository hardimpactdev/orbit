<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Shared\ResourceOperationException;

/**
 * Takes a free task VM slot for one proof, in proof mode: no Pi token, no model key and no route to the real Gateway
 * ([Task VM slots](/reference/compute-drivers#task-vm-slots)). The slot claim of the task VM family owns this; the
 * proof run only asks for a slot and holds it until its terminal result.
 */
interface ProofSlotClaim
{
    /** @throws ResourceOperationException proof.no_slot */
    public function claim(): ProofSlot;

    /**
     * Frees the slot after the run ends, however it ended. The claim also frees a slot whose Operation ended with no
     * projection, such as one cancelled before it started: the host sweep of the chart does that.
     */
    public function release(string $name): void;
}
