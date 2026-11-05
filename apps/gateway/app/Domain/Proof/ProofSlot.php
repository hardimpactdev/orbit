<?php

declare(strict_types=1);

namespace App\Domain\Proof;

/** A task VM slot that a proof holds. */
final readonly class ProofSlot
{
    /** @param string $checkout the slot checkout, clean at the proved commit */
    public function __construct(public string $name, public int $nodeId, public string $checkout) {}
}
