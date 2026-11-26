<?php

declare(strict_types=1);

namespace App\Domain\Engine;

use App\Models\Operation;

/**
 * A receipt-bound kind whose steps answer evidence: a successful receipt settles its step only when the output is that evidence
 * (docs/reference/gateway-engine.md). Any other output fails the step as `engine.receipt_invalid`.
 */
interface EngineEvidence
{
    public function evidence(Operation $operation, string $step, string $output): bool;
}
