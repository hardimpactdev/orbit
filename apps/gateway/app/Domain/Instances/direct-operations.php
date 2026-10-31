<?php

declare(strict_types=1);

use App\Domain\Instances\Environment\NativeInstanceEnvironmentProjection;
use App\Domain\Instances\InstanceDestroyProjection;
use App\Domain\Instances\NativeInstanceProvisioningProjection;
use App\Domain\Instances\InstanceRenameResult;
use App\Domain\Instances\InstanceRegisterResult;
use App\Domain\Instances\StepLists\InstanceSetupProjection;

return [
    'instance.create' => ['workflow' => 'operation-instance-create', 'projection' => NativeInstanceProvisioningProjection::class,
        'private_material' => ['git_read' => 'instance.lifecycle_git', 'environment_material' => 'instance.environment_sync', 'setup_programs' => 'instance.provisioning_setup'],
        'secret_outputs' => ['environment_template' => 'instance.create_environment']],
    'instance.clone' => ['workflow' => 'operation-instance-clone', 'projection' => NativeInstanceProvisioningProjection::class,
        'private_material' => ['git_read' => 'instance.lifecycle_git', 'environment_material' => 'instance.environment_sync']],
    'instance.rename' => ['workflow' => 'operation-instance-rename', 'projection' => InstanceRenameResult::class],
    'instance.register' => ['workflow' => 'operation-instance-register', 'projection' => InstanceRegisterResult::class],
    'instance.sync_environment' => [
        'workflow' => 'operation-instance-environment',
        'projection' => NativeInstanceEnvironmentProjection::class,
        'private_material' => ['environment_material' => 'instance.environment_sync'],
    ],
    'instance.destroy' => [
        'workflow' => 'operation-instance-destroy',
        'projection' => InstanceDestroyProjection::class,
        'private_material' => ['git_read' => 'instance.lifecycle_git', 'teardown' => 'instance.destroy_teardown', 'php' => 'instance.destroy_php'],
    ],
    'instance.run_steps' => [
        'workflow' => 'operation-instance-setup-direct',
        'projection' => InstanceSetupProjection::class,
    ],
];
