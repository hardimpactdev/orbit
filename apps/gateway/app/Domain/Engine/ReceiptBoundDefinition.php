<?php

declare(strict_types=1);

namespace App\Domain\Engine;

use App\Models\Operation;

/**
 * An engine kind whose Node work cannot be repeated blind or settled without the Node's answer (ADR 0271): its run binds with
 * its steps and takes its target's Operation lock in one transaction, before the start event, and keeps it until a terminal
 * receipt. `lockKey` runs inside that transaction and throws `operation.target_busy` when the target is not free.
 */
interface ReceiptBoundDefinition extends EngineDefinition
{
    /** The Operation lock the run holds; throws ResourceOperationException `operation.target_busy` when the target is taken. */
    public function lockKey(Operation $operation): string;
}
