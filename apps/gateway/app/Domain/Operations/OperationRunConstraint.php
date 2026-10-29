<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/** A named server-owned constraint, evaluated before hydration and effect dispatch. */
interface OperationRunConstraint
{
    public function name(): string;

    /** @param array<string, mixed> $constraint Server-recorded parameters. */
    public function assertAllowed(Operation $operation, array $constraint): void;
}
