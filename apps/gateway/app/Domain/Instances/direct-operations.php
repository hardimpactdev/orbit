<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceDestroyProjection;
use App\Domain\Instances\InstanceTransferDiscardProjection;
use App\Domain\Instances\StepLists\InstanceSetupProjection;

return [
    'instance.destroy_development' => [
        'workflow' => 'operation-instance-destroy',
        'writer_config' => 'swamp.direct_writers',
        'writer_state' => 'instance_destroy_writer',
        'projection' => InstanceDestroyProjection::class,
        'private_material' => ['git_read' => 'instance.lifecycle_git', 'teardown' => 'instance.destroy_teardown', 'php' => 'instance.destroy_php'],
    ],
    'instance.run_steps' => [
        'workflow' => 'operation-instance-setup-direct',
        'writer_config' => 'swamp.direct_writers',
        'writer_state' => 'instance_step_writer',
        'projection' => InstanceSetupProjection::class,
    ],
    'instance.transfer_discard' => [
        'workflow' => 'operation-instance-lifecycle-direct',
        'writer_config' => 'swamp.direct_writers',
        'writer_state' => 'instance_lifecycle_writer',
        'projection' => InstanceTransferDiscardProjection::class,
    ],
];
