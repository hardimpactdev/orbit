<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** Unported callers cannot admit the retired child-based lifecycle. */
final readonly class StartTaskVmLifecycleOperationAction
{
    public function execute(int $taskVmId, string $verb): ?Operation
    {
        throw new ResourceOperationException('operation.kind_unavailable', 'Use the owning native Task VM lifecycle admission.', 409);
    }
}
