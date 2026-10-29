<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\AutomaticDeployEventOperation;
use App\Domain\Instances\Smoke\InstanceSmokeOperation;
use App\Domain\Operations\OperationKinds;

$family = require __DIR__.'/lifecycle-operation-kinds.php';
$enabled = ['instance.destroy', 'instance.create', 'instance.rename', 'instance.register', 'instance.clone'];
// The relay slice and its complete destination/activation handlers land separately.
if (OperationKinds::supports('archive_relay') && OperationKinds::supports('compensations')
    && isset($family['instance.transfer_destination'], $family['instance.transfer_activate'])) {
    $enabled = [...$enabled, 'instance.transfer', 'instance.transfer_destination', 'instance.transfer_activate',
        'instance.transfer_rollback', 'instance.transfer_discard'];
}
$lifecycle = OperationKinds::supports('children') && OperationKinds::supports('receipts') && OperationKinds::supports('list_of')
    ? array_intersect_key($family, array_flip($enabled)) : [];

return [
    ...require __DIR__.'/environment-operation-kinds.php',
    ...require __DIR__.'/deployment-operation-kinds.php',
    ...$lifecycle,
    OperationKinds::InstanceSmoke => InstanceSmokeOperation::class,
    OperationKinds::InstancePolicyEvent => AutomaticDeployEventOperation::class,
];
