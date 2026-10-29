<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Actions\Instances\StartInstanceEnvironmentSyncAction;
use App\Domain\Operations\OperationChildAdmission;
use App\Models\Operation;

final readonly class EnvironmentSyncAdmission implements OperationChildAdmission
{
    public function __construct(private StartInstanceEnvironmentSyncAction $admission) {}

    public function admit(Operation $parent, array $intent): Operation
    {
        return $this->admission->execute($parent, $intent);
    }
}
