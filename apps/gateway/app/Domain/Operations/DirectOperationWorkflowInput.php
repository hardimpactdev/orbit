<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/** Native submission may publish execution copies; public reads must remain effect-free. */
interface DirectOperationWorkflowInput
{
    /** @return array<string, mixed> */
    public function workflowInput(Operation $operation): array;
}
