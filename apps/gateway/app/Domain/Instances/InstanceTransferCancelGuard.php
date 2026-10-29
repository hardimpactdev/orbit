<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Operations\OperationCancelGuard;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** Cutover is Gateway-owned; cancellation cannot leave the authoritative destination unactivated. */
final readonly class InstanceTransferCancelGuard implements OperationCancelGuard
{
    public function assertCancellable(Operation $operation): void
    {
        $transfer = app(InstanceTransferState::class)->transfer($operation);
        if ($transfer->cutover_at !== null) {
            throw new ResourceOperationException('operation.cancel_refused', 'The transfer has committed cutover and must finish forward.', 409);
        }
    }
}
