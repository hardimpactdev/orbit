<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Actions\TaskVms\AdmitTaskVmChildAction;
use App\Domain\Operations\OperationChildAdmission;
use App\Models\Operation;

/** The shared kit owns parent stamps, keyed replay and target-lock scope. */
final readonly class TaskVmChildAdmission implements OperationChildAdmission
{
    public function __construct(private AdmitTaskVmChildAction $admission) {}

    /** @param array<string,mixed> $intent */
    public function admit(Operation $parent, array $intent): Operation
    {
        return $this->admission->execute($parent, $intent);
    }
}
