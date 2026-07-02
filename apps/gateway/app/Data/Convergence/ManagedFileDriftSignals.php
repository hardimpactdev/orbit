<?php

declare(strict_types=1);

namespace App\Data\Convergence;

final readonly class ManagedFileDriftSignals
{
    public function __construct(
        public string $missingKey,
        public string $mismatchKey,
        public string $probeFailedKey,
        public string $label,
    ) {}
}
