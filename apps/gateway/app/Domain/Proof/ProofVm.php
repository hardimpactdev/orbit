<?php

declare(strict_types=1);

namespace App\Domain\Proof;

/** A VM that a proof runs on. */
final readonly class ProofVm
{
    /** @param string $checkout the VM checkout, clean at the proved commit */
    public function __construct(public string $name, public int $nodeId, public string $checkout) {}
}
