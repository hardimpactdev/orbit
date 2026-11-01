<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\AutomaticDeployActivityProjection;
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
    'instance.policy_event' => [
        'workflow' => 'operation-instance-policy-activity',
        'workflow_inputs' => true,
        'terminal_result' => ['job' => 'record', 'step' => 'record'],
        'projection' => AutomaticDeployActivityProjection::class,
    ],
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
        'github_token_issuer' => InstanceGitHubReadTokens::class,
        'terminal_result' => ['job' => 'create', 'step' => 'finish-result', 'captures' => [
            'source' => ['job' => 'prepare', 'step' => 'source-result'],
            'environment_seeded_keys' => ['job' => 'prepare', 'step' => 'env-capture', 'field' => 'keys'],
            'vp_environment' => ['job' => 'vp-install', 'step' => 'vp-result', 'field' => 'runtime'],
        ]],
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^instance-(?:git-operation-|create-environment-(?:input-|result-)|create-)[1-9][0-9]*(?:-setup-[0-9]+)?$']]],
    'instance.sync_environment' => [
        'workflow' => 'operation-instance-environment',
        'projection' => NativeInstanceEnvironmentProjection::class,
        'private_material' => ['environment_material' => 'instance.environment_sync'],
    ],
    'instance.register' => ['workflow' => 'operation-instance-register', 'workflow_inputs' => true, 'terminal_result' => ['job' => 'source', 'step' => 'result'], 'projection' => InstanceRegisterResult::class],
    'instance.destroy' => ['workflow' => 'operation-instance-destroy', 'workflow_inputs' => true,
        'projection' => DevelopmentInstanceDestroyProjection::class,
        'github_token_issuer' => InstanceGitHubReadTokens::class,
        'terminal_result' => ['job' => 'destroy', 'step' => 'remove-result', 'captures' => ['source_inspection' => ['job' => 'destroy', 'step' => 'destroy-inspect']]],
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^instance-(?:git-operation-[1-9][0-9]*|destroy-[1-9][0-9]*-teardown-[0-9]+)$'], ['vault' => 'orbit-control-inputs', 'key_pattern' => '^route-[a-f0-9]{16}-(?:forward|restore)-route_material_[0-9]+$']]],
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
    'instance.run_steps' => [
        'workflow' => 'operation-instance-setup-direct',
        'writer_config' => 'swamp.direct_writers',
        'writer_state' => 'instance_step_writer',
        'projection' => InstanceSetupProjection::class,
    ],
];
