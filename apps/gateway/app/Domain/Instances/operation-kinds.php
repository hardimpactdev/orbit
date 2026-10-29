<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\AutomaticDeployEventOperation;
use App\Domain\Instances\Smoke\InstanceSmokeOperation;
use App\Domain\Operations\OperationKinds;

$lifecycle = OperationKinds::supports('children') && OperationKinds::supports('receipts') && OperationKinds::supports('list_of')
    ? array_intersect_key(require __DIR__.'/lifecycle-operation-kinds.php', array_flip(['instance.destroy', 'instance.create', 'instance.rename', 'instance.register', 'instance.clone'])) : [];
// Transfer requires its archive_relay integration before registration.
unset($lifecycle['instance.transfer'], $lifecycle['instance.transfer_destination'], $lifecycle['instance.transfer_rollback'], $lifecycle['instance.transfer_discard']);

return [
    ...require __DIR__.'/environment-operation-kinds.php',
    ...require __DIR__.'/deployment-operation-kinds.php',
    ...$lifecycle,
    OperationKinds::InstanceSmoke => InstanceSmokeOperation::class,
    OperationKinds::InstancePolicyEvent => AutomaticDeployEventOperation::class,
];
