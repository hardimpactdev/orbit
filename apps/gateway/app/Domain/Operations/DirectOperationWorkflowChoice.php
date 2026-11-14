<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Models\Operation;

/**
 * A kind that runs one of several declared workflows, by its admitted intent. Swamp resolves every step's inputs when a run starts,
 * also of a step that a guard will skip, so a workflow may read only keys that its intent has: an intent of another shape takes its own
 * workflow. The choice is one of the declaration's `workflow` and `alternate_workflows`.
 */
interface DirectOperationWorkflowChoice
{
    public function workflow(Operation $operation): string;
}
