<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Operations\OperationCancelGuard;
use App\Domain\Shared\ResourceOperationException;
use App\Models\InstanceRemoval;
use App\Models\Operation;

final readonly class InstanceDestroyCancelGuard implements OperationCancelGuard
{
    public function assertCancellable(Operation $operation): void
    {
        $removalId = $operation->input['removal_id'] ?? null;
        if (is_string($removalId) && InstanceRemoval::query()->whereKey($removalId)->exists()) {
            throw new ResourceOperationException('operation.cancel_refused', 'Accepted Instance removal must finish or resume.', 409);
        }
    }
}
