<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\OperationRun;
use Orbit\Core\Enums\OperationStatus;

final readonly class InstalledArtifactRunStatus
{
    public function isTrusted(string $operationRunId): bool
    {
        $operationRunId = trim($operationRunId);

        if ($operationRunId === '') {
            return true;
        }

        $operationRun = OperationRun::query()->find($operationRunId);

        if (! $operationRun instanceof OperationRun) {
            return true;
        }

        return $operationRun->status === OperationStatus::Succeeded;
    }
}
