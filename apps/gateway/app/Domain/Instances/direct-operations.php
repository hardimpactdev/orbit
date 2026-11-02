<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\DatabaseServerAdminSecret;
use App\Domain\Instances\Deployment\InstanceDeployProjection;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\DevelopmentInstanceDestroyProjection;
use App\Domain\Instances\Environment\NativeInstanceEnvironmentProjection;
use App\Domain\Instances\InstanceCloneProjection;
use App\Domain\Instances\InstanceGitHubReadTokens;
use App\Domain\Instances\InstanceRegisterResult;
use App\Domain\Instances\InstanceRenameResult;
use App\Domain\Instances\NativeInstanceTransferProjection;
use App\Domain\Instances\StepLists\InstanceSetupProjection;

return [
    'instance.transfer' => ['workflow' => 'operation-instance-transfer', 'workflow_inputs' => true,
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^route-[a-f0-9]{16}-(forward|restore)-route_material_[0-9]+$'],
            ['vault' => 'orbit-control-inputs', 'key_pattern' => '^process-parent-operation-[1-9][0-9]*-node-[1-9][0-9]*-member-[1-9][0-9]*$']],
        'terminal_result' => ['job' => 'transfer', 'step' => 'result'], 'projection' => NativeInstanceTransferProjection::class],
    'instance.clone' => ['workflow' => 'operation-instance-clone', 'workflow_inputs' => true,
        'github_token_issuer' => InstanceGitHubReadTokens::class,
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^instance-git-operation-[1-9][0-9]*$'],
            ['vault' => 'orbit-control-inputs', 'key_pattern' => '^instance-clone-environment-operation-[1-9][0-9]*$']],
        'terminal_result' => ['job' => 'clone', 'step' => 'result'], 'projection' => InstanceCloneProjection::class],
    'instance.create' => ['workflow' => 'operation-instance-create', 'workflow_inputs' => true,
        'projection' => DevelopmentInstanceCreateProjection::class,
        'terminal_result' => ['job' => 'create', 'step' => 'finish'],
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^(?:instance-git-operation-[1-9][0-9]*|instance-create-environment-input-[1-9][0-9]*|instance-create-[1-9][0-9]*)$'], ['vault' => 'orbit-control-inputs', 'key_pattern' => '^process-instance-operation-[1-9][0-9]*-member-[1-9][0-9]*$']]],
    'instance.sync_environment' => ['workflow' => 'operation-instance-environment', 'workflow_inputs' => true,
        'terminal_result' => ['job' => 'environment', 'step' => 'Report the result'],
        'projection' => NativeInstanceEnvironmentProjection::class,
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^instance-environment-[1-9][0-9]*$']]],
    'instance.register' => ['workflow' => 'operation-instance-register', 'workflow_inputs' => true, 'terminal_result' => ['job' => 'source', 'step' => 'result'], 'projection' => InstanceRegisterResult::class],
    'instance.destroy' => ['workflow' => 'operation-instance-destroy', 'workflow_inputs' => true,
        'projection' => DevelopmentInstanceDestroyProjection::class,
        'terminal_result' => ['job' => 'destroy', 'step' => 'Report the result'],
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^instance-git-operation-[1-9][0-9]*$'], ['vault' => 'orbit-control-inputs', 'key_pattern' => '^route-[a-f0-9]{16}-(?:forward|restore)-route_material_[0-9]+$'], DatabaseServerAdminSecret::EXECUTION_COPY]],
    'instance.rename' => ['workflow' => 'operation-instance-rename', 'workflow_inputs' => true, 'terminal_result' => ['job' => 'source', 'step' => 'result'], 'projection' => InstanceRenameResult::class],
    'instance.deploy' => [
        'workflow' => 'operation-instance-deploy-direct',
        'terminal_result' => ['job' => 'development-result', 'step' => 'result'],
        'workflow_inputs' => true,
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^process-parent-operation-[1-9][0-9]*-node-[1-9][0-9]*-member-[1-9][0-9]*$'], ['vault' => 'orbit-control-inputs', 'key_pattern' => '^development-deploy-operation-[1-9][0-9]*-step-(?:[0-9]|[12][0-9]|3[01])$']],
        'projection' => InstanceDeployProjection::class,
        'private_material' => ['git_read' => 'instance.deployment', 'vp_materialize' => 'instance.deployment',
            'environment_material' => 'instance.deployment', 'definitions_material' => 'instance.deployment'],
    ],
    'instance.run_steps' => ['workflow' => 'operation-instance-setup-direct', 'workflow_inputs' => true,
        'terminal_result' => ['job' => 'setup', 'step' => 'Report the result'], 'projection' => InstanceSetupProjection::class],
];
