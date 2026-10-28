<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\AutomaticDeployEventOperation;
use App\Domain\Instances\Smoke\InstanceSmokeOperation;
use App\Domain\Operations\OperationKinds;

return [...require __DIR__.'/environment-operation-kinds.php', ...require __DIR__.'/deployment-operation-kinds.php',
    OperationKinds::InstanceSmoke => InstanceSmokeOperation::class,
    OperationKinds::InstancePolicyEvent => AutomaticDeployEventOperation::class,
];
