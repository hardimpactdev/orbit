<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\DatabaseDestroyRun;
use App\Domain\Instances\Deployment\InstanceDeployProjection;
use App\Domain\Instances\Deployment\InstanceRollbackProjection;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\DevelopmentInstanceDestroyProjection;
use App\Domain\Instances\Environment\NativeInstanceEnvironmentImportProjection;
use App\Domain\Instances\Environment\NativeInstanceEnvironmentProjection;
use App\Domain\Instances\InstanceCloneProjection;
use App\Domain\Instances\InstanceGitHubReadTokens;
use App\Domain\Instances\InstanceRegisterResult;
use App\Domain\Instances\NativeInstanceTransferProjection;
use App\Domain\Instances\StepLists\InstanceSetupProjection;

return [
    'instance.transfer' => ['workflow' => 'operation-instance-transfer', 'workflow_inputs' => true,
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^route-[a-f0-9]{16}-(forward|restore)-route_material_[0-9]+$'],
            ['vault' => 'orbit-control-inputs', 'key_pattern' => '^process-parent-operation-[1-9][0-9]*-node-[1-9][0-9]*-member-[1-9][0-9]*$'],
            ['vault' => 'orbit-control-inputs', 'key_pattern' => '^instance-transfer-environment-[1-9][0-9]*$']],
        // Retire ends a moved transfer and Discard a restored one; the Route children's results say which happened.
        'terminal_result' => ['job' => 'transfer', 'step' => 'Retire the source', 'alternatives' => ['Discard the destination'], 'native' => true,
            'captures' => ['forward' => ['job' => 'transfer', 'step' => 'Publish the Route', 'field' => 'result'],
                'restore' => ['job' => 'transfer', 'step' => 'Restore the Route', 'field' => 'result'],
                'source_removal' => ['job' => 'transfer', 'step' => 'Clean retirement files', 'field' => 'stdout']]],
        'projection' => NativeInstanceTransferProjection::class],
    'instance.clone' => ['workflow' => 'operation-instance-clone', 'workflow_inputs' => true,
        'github_token_issuer' => InstanceGitHubReadTokens::class,
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^instance-git-(?:operation|credential)-[1-9][0-9]*$'],
            ['vault' => 'orbit-control-inputs', 'key_pattern' => '^instance-clone-environment-operation-[1-9][0-9]*$'],
            ['vault' => 'orbit-control-inputs', 'key_pattern' => '^route-[a-f0-9]{16}-forward-route_material_[0-9]+$']],
        'terminal_result' => ['job' => 'clone', 'step' => 'Report the result'], 'projection' => InstanceCloneProjection::class],
    'instance.create' => ['workflow' => 'operation-instance-create', 'workflow_inputs' => true,
        'projection' => DevelopmentInstanceCreateProjection::class,
        'terminal_result' => ['job' => 'create', 'step' => 'finish'],
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^(?:instance-git-(?:operation|credential)-[1-9][0-9]*|instance-create-environment-(?:input|testing)-[1-9][0-9]*|instance-create-(?:model|processes)-[1-9][0-9]*)$'], ['vault' => 'orbit-control-inputs', 'key_pattern' => '^process-instance-operation-[1-9][0-9]*-member-[1-9][0-9]*$'],
            ['vault' => 'orbit-control-inputs', 'key_pattern' => '^nckrtl-instance-t3-[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}-register-session-(?:gateway|swamp)-[1-9][0-9]*-token$'],
            ['vault' => 'orbit-control-inputs', 'key_pattern' => '^route-[a-f0-9]{16}-(?:forward|restore)-route_material_[0-9]+$'],
            // A container default runs the Project's development deploy steps, each from its own copy.
            ['vault' => 'orbit-control-inputs', 'key_pattern' => '^development-deploy-operation-[1-9][0-9]*-step-(?:[0-9]|[12][0-9]|3[01])$'],
            // The owned server database: the same copies as database:create.
            ['vault' => 'orbit-control-inputs', 'key_pattern' => '^database-create-intent-[1-9][0-9]*$'], ['vault' => 'orbit-control-inputs', 'key_pattern' => '^database-create-owner-[1-9][0-9]*$']]],
    'instance.env_import' => ['workflow' => 'operation-instance-env-import', 'workflow_inputs' => true,
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^instance-env-import\.content\.[1-9][0-9]*$']],
        'terminal_result' => ['job' => 'import', 'step' => 'Read .env into the vault', 'native' => true,
            'captures' => ['size' => ['job' => 'import', 'step' => 'Read .env into the vault', 'field' => 'size'],
                'sha256' => ['job' => 'import', 'step' => 'Read .env into the vault', 'field' => 'sha256']]],
        'projection' => NativeInstanceEnvironmentImportProjection::class],
    'instance.sync_environment' => ['workflow' => 'operation-instance-environment', 'workflow_inputs' => true,
        // The file behavior reports `compliant` for a file it left in place and `applied` for one it wrote.
        'terminal_result' => ['job' => 'environment', 'step' => 'Check the written file', 'native' => true, 'captures' => [
            'environment_write' => ['job' => 'environment', 'step' => 'Write .env, .env.testing-0', 'field' => 'status'],
            'testing_write' => ['job' => 'environment', 'step' => 'Write .env, .env.testing-1', 'field' => 'status']]],
        'projection' => NativeInstanceEnvironmentProjection::class,
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^instance-environment-(?:testing-)?[1-9][0-9]*$']]],
    'instance.register' => ['workflow' => 'operation-instance-register', 'workflow_inputs' => true, 'projection' => InstanceRegisterResult::class,
        'terminal_result' => ['job' => 'register', 'step' => 'Create the Route', 'alternatives' => ['Protect the .env', 'Run Project setup steps-0', 'Create, start Processes-0'], 'native' => true],
        'execution_copies' => [
            ['vault' => 'orbit-control-inputs', 'key_pattern' => '^process-instance-operation-[1-9][0-9]*-member-[1-9][0-9]*$'],
            ['vault' => 'orbit-control-inputs', 'key_pattern' => '^route-[a-f0-9]{16}-(?:forward|restore)-route_material_[0-9]+$'],
        ]],
    'instance.destroy' => ['workflow' => 'operation-instance-destroy', 'workflow_inputs' => true,
        'projection' => DevelopmentInstanceDestroyProjection::class,
        // The Route child allows its own step failures; its ready result says whether the withdrawal held.
        'terminal_result' => ['job' => 'destroy', 'step' => 'Check it is gone', 'alternatives' => ['Read container removal'], 'native' => true,
            'captures' => ['withdrawal' => ['job' => 'destroy', 'step' => 'Withdraw the Route', 'field' => 'result'],
                'source_removal' => ['job' => 'destroy', 'step' => 'Read source removal', 'field' => 'stdout']]],
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^instance-git-(?:operation|credential)-[1-9][0-9]*$'], ['vault' => 'orbit-control-inputs', 'key_pattern' => '^route-[a-f0-9]{16}-(?:forward|restore)-route_material_[0-9]+$'], DatabaseDestroyRun::OWNED_EXECUTION_COPY]],
    'instance.deploy' => [
        'workflow' => 'operation-instance-deploy-direct',
        // Development ends with the checkout's end check, a container default with its container's, production with the deployment's; the Vite+ runtime the steps
        // ran under comes as one capture. Every step runs on Node 209 over official SSH.
        'terminal_result' => ['job' => 'deploy', 'step' => 'Check the checkout', 'alternatives' => ['Check the deployment', 'Check the container'], 'native' => true,
            'captures' => ['vp_runtime' => ['job' => 'dev-vp-workspaces', 'step' => 'dev-vp-requirements', 'field' => 'runtime'],
                'php_runtime' => ['job' => 'deploy', 'step' => 'Check PHP runtime', 'field' => 'status']]],
        'workflow_inputs' => true,
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^process-parent-operation-[1-9][0-9]*-node-[1-9][0-9]*-member-[1-9][0-9]*$'],
            ['vault' => 'orbit-control-inputs', 'key_pattern' => '^development-deploy-operation-[1-9][0-9]*-step-(?:[0-9]|[12][0-9]|3[01])$'],
            ['vault' => 'orbit-control-inputs', 'key_pattern' => '^development-deploy-operation-[1-9][0-9]*-(?:credential|processes)$'],
            ['vault' => 'orbit-control-inputs', 'key_pattern' => '^instance-deploy-operation-[1-9][0-9]*-step-(?:[0-9]|[12][0-9]|3[01])$'],
            ['vault' => 'orbit-control-inputs', 'key_pattern' => '^instance-deploy-operation-[1-9][0-9]*-credential$'],
            ['vault' => 'orbit-control-inputs', 'key_pattern' => '^instance-environment-(?:testing-)?[1-9][0-9]*$']],
        'projection' => InstanceDeployProjection::class,
    ],
    // The link read back after the swap is the end check; every step runs on Node 209 over official SSH.
    'instance.rollback' => ['workflow' => 'operation-instance-rollback', 'workflow_inputs' => true,
        'terminal_result' => ['job' => 'rollback', 'step' => 'Check the selection', 'native' => true], 'projection' => InstanceRollbackProjection::class],
    'instance.run_steps' => ['workflow' => 'operation-instance-setup-direct', 'workflow_inputs' => true,
        'terminal_result' => ['job' => 'setup', 'step' => 'Check every step passed', 'native' => true], 'projection' => InstanceSetupProjection::class],
];
