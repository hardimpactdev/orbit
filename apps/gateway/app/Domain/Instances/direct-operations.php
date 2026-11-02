<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\DatabaseDestroyRun;
use App\Domain\Instances\Deployment\InstanceDeployProjection;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\DevelopmentInstanceDestroyProjection;
use App\Domain\Instances\Environment\NativeInstanceEnvironmentImportProjection;
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
            ['vault' => 'orbit-control-inputs', 'key_pattern' => '^process-parent-operation-[1-9][0-9]*-node-[1-9][0-9]*-member-[1-9][0-9]*$'],
            ['vault' => 'orbit-control-inputs', 'key_pattern' => '^instance-transfer-environment-[1-9][0-9]*$']],
        // Retire ends a moved transfer and Discard a restored one; the Route children's results say which happened.
        'terminal_result' => ['job' => 'transfer', 'step' => 'Retire the source', 'alternatives' => ['Discard the destination'], 'native' => true,
            'captures' => ['forward' => ['job' => 'transfer', 'step' => 'Publish the Route', 'field' => 'result'],
                'restore' => ['job' => 'transfer', 'step' => 'Restore the Route', 'field' => 'result']]],
        'projection' => NativeInstanceTransferProjection::class],
    'instance.clone' => ['workflow' => 'operation-instance-clone', 'workflow_inputs' => true,
        'github_token_issuer' => InstanceGitHubReadTokens::class,
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^instance-git-(?:operation|credential)-[1-9][0-9]*$'],
            ['vault' => 'orbit-control-inputs', 'key_pattern' => '^instance-clone-environment-operation-[1-9][0-9]*$']],
        'terminal_result' => ['job' => 'clone', 'step' => 'result'], 'projection' => InstanceCloneProjection::class],
    'instance.create' => ['workflow' => 'operation-instance-create', 'workflow_inputs' => true,
        'projection' => DevelopmentInstanceCreateProjection::class,
        'terminal_result' => ['job' => 'create', 'step' => 'finish'],
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^(?:instance-git-(?:operation|credential)-[1-9][0-9]*|instance-create-environment-input-[1-9][0-9]*)$'], ['vault' => 'orbit-control-inputs', 'key_pattern' => '^process-instance-operation-[1-9][0-9]*-member-[1-9][0-9]*$']]],
    'instance.env_import' => ['workflow' => 'operation-instance-env-import', 'workflow_inputs' => true,
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^instance-env-import\.content\.[1-9][0-9]*$']],
        'terminal_result' => ['job' => 'import', 'step' => 'Read .env into the vault', 'native' => true,
            'captures' => ['size' => ['job' => 'import', 'step' => 'Read .env into the vault', 'field' => 'size'],
                'sha256' => ['job' => 'import', 'step' => 'Read .env into the vault', 'field' => 'sha256']]],
        'projection' => NativeInstanceEnvironmentImportProjection::class],
    'instance.sync_environment' => ['workflow' => 'operation-instance-environment', 'workflow_inputs' => true,
        'terminal_result' => ['job' => 'environment', 'step' => 'Check the written file', 'native' => true],
        'projection' => NativeInstanceEnvironmentProjection::class,
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^instance-environment-(?:testing-)?[1-9][0-9]*$']]],
    'instance.register' => ['workflow' => 'operation-instance-register', 'workflow_inputs' => true, 'projection' => InstanceRegisterResult::class,
        // The native result cuts stdout short, so each read comes as one capture for the Gateway's fact check.
        'terminal_result' => ['job' => 'source', 'step' => 'Protect the .env file', 'native' => true, 'captures' => array_map(
            static fn (string $step): array => ['job' => 'source', 'step' => $step, 'field' => 'stdout'],
            ['filesystem' => 'Read the checkout files', 'top' => 'Read the Git top level', 'common' => 'Read the Git common dir',
                'worktrees' => 'List the worktrees', 'origin' => 'Read the origin', 'branch' => 'Read the branch',
                'commit' => 'Read the commit', 'default' => 'Read the default branch'],
        )]],
    'instance.destroy' => ['workflow' => 'operation-instance-destroy', 'workflow_inputs' => true,
        'projection' => DevelopmentInstanceDestroyProjection::class,
        'terminal_result' => ['job' => 'destroy', 'step' => 'Check it is gone', 'native' => true],
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^instance-git-(?:operation|credential)-[1-9][0-9]*$'], ['vault' => 'orbit-control-inputs', 'key_pattern' => '^route-[a-f0-9]{16}-(?:forward|restore)-route_material_[0-9]+$'], DatabaseDestroyRun::OWNED_EXECUTION_COPY]],
    'instance.rename' => ['workflow' => 'operation-instance-rename', 'workflow_inputs' => true, 'terminal_result' => ['job' => 'source', 'step' => 'result'], 'projection' => InstanceRenameResult::class],
    'instance.deploy' => [
        'workflow' => 'operation-instance-deploy-direct',
        // Development: the checkout's end check; the Vite+ runtime the steps ran under comes as one capture.
        'terminal_result' => ['job' => 'development-steps', 'step' => 'Check the checkout', 'native' => true,
            'captures' => ['vp_runtime' => ['job' => 'dev-vp-workspaces', 'step' => 'dev-vp-requirements', 'field' => 'runtime']]],
        'workflow_inputs' => true,
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^process-parent-operation-[1-9][0-9]*-node-[1-9][0-9]*-member-[1-9][0-9]*$'], ['vault' => 'orbit-control-inputs', 'key_pattern' => '^development-deploy-operation-[1-9][0-9]*-step-(?:[0-9]|[12][0-9]|3[01])$'],
            ['vault' => 'orbit-control-inputs', 'key_pattern' => '^development-deploy-operation-[1-9][0-9]*-credential$']],
        'projection' => InstanceDeployProjection::class,
        'private_material' => ['git_read' => 'instance.deployment', 'vp_materialize' => 'instance.deployment',
            'environment_material' => 'instance.deployment', 'definitions_material' => 'instance.deployment'],
    ],
    'instance.run_steps' => ['workflow' => 'operation-instance-setup-direct', 'workflow_inputs' => true,
        'terminal_result' => ['job' => 'setup', 'step' => 'Check every step passed', 'native' => true], 'projection' => InstanceSetupProjection::class],
];
