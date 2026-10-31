<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\InstanceDeployProjection;
use App\Domain\Instances\Environment\NativeInstanceEnvironmentProjection;
use App\Domain\Instances\InstanceCloneProjection;
use App\Domain\Instances\InstanceDestroyProjection;
use App\Domain\Instances\InstanceRegisterResult;
use App\Domain\Instances\InstanceRenameResult;
use App\Domain\Instances\NativeInstanceProvisioningProjection;
use App\Domain\Instances\NativeInstanceTransferProjection;
use App\Domain\Instances\StepLists\InstanceSetupProjection;

return [
    'instance.transfer' => ['workflow' => 'operation-instance-transfer', 'projection' => NativeInstanceTransferProjection::class,
        'private_material' => ['source_route_environment' => 'instance.transfer_route_environment', 'destination_route_environment' => 'instance.transfer_route_environment']],
    'instance.clone' => ['workflow' => 'operation-instance-clone', 'projection' => InstanceCloneProjection::class,
        'private_material' => ['git_read' => 'instance.lifecycle_git', 'environment_material' => 'instance.environment_sync']],
    'instance.create' => ['workflow' => 'operation-instance-create', 'projection' => NativeInstanceProvisioningProjection::class,
        'private_material' => ['git_read' => 'instance.lifecycle_git', 'environment_material' => 'instance.environment_sync', 'setup_programs' => 'instance.provisioning_setup'],
        'secret_outputs' => ['environment_template' => 'instance.create_environment']],
    'instance.sync_environment' => [
        'workflow' => 'operation-instance-environment',
        'projection' => NativeInstanceEnvironmentProjection::class,
        'private_material' => ['environment_material' => 'instance.environment_sync'],
    ],
    'instance.register' => ['workflow' => 'operation-instance-register', 'projection' => InstanceRegisterResult::class],
    'instance.destroy' => [
        'workflow' => 'operation-instance-destroy',
        'projection' => InstanceDestroyProjection::class,
        'private_material' => ['git_read' => 'instance.lifecycle_git', 'teardown' => 'instance.destroy_teardown', 'php' => 'instance.destroy_php', 'database_material' => 'database.intent'],
    ],
    'instance.rename' => ['workflow' => 'operation-instance-rename', 'projection' => InstanceRenameResult::class],
    'instance.deploy' => [
        'workflow' => 'operation-instance-deploy-direct',
        'projection' => InstanceDeployProjection::class,
        'private_material' => ['git_read' => 'instance.deployment', 'vp_materialize' => 'instance.deployment',
            'environment_material' => 'instance.deployment', 'definitions_material' => 'instance.deployment'],
    ],
    'instance.run_steps' => [
        'workflow' => 'operation-instance-setup-direct',
        'writer_config' => 'swamp.direct_writers',
        'writer_state' => 'instance_step_writer',
        'projection' => InstanceSetupProjection::class,
    ],
];
