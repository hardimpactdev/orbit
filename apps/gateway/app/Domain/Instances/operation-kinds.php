<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\AutomaticDeployEventOperation;
use App\Domain\Instances\Deployment\InstanceDeployOperation;
use App\Domain\Instances\Deployment\InstanceRollbackOperation;
use App\Domain\Instances\Smoke\InstanceSmokeOperation;
use App\Domain\Operations\OperationKinds;

return [
    ...require __DIR__.'/environment-operation-kinds.php',
    OperationKinds::InstanceSmoke => InstanceSmokeOperation::class,
    OperationKinds::InstanceDeploy => InstanceDeployOperation::class,
    OperationKinds::InstancePolicyEvent => AutomaticDeployEventOperation::class,
    OperationKinds::InstanceRollback => InstanceRollbackOperation::class,
];
