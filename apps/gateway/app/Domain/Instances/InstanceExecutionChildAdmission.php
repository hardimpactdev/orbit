<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\Instances\StartInstanceExecutionChildAction;
use App\Domain\Operations\OperationChildAdmission;
use App\Models\Operation;

final readonly class InstanceExecutionChildAdmission implements OperationChildAdmission
{
    public function __construct(private StartInstanceExecutionChildAction $admission) {}

    public function admit(Operation $parent, array $intent): Operation
    {
        return $this->admission->execute($parent, $intent);
    }
}
