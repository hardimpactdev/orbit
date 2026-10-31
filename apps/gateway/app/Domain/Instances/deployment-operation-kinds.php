<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\InstanceDeploymentExecution;
use App\Domain\Instances\Deployment\InstanceProjectionExecution;
use App\Domain\Instances\Environment\InstanceServedWebRootsExecution;
use App\Domain\Instances\InstanceExecutionChildAdmission;
use App\Domain\Instances\PhpRuntime\InstancePhpRuntimeExecution;
use App\Domain\Instances\VpEnvironment\InstanceVpEnvironmentExecution;
use App\Domain\Operations\OperationKinds;

$deployment = static fn (bool $rollback): array => [
    'executor' => 'swamp',
    'execution' => InstanceDeploymentExecution::class,
    'completion' => InstanceDeploymentExecution::class,
    'runs' => [
        ['transport' => 'instance-deploy-agent', 'name' => 'instance.inspect-release', 'resumable' => true],
        ['transport' => 'instance-deploy-agent', 'name' => 'instance.activate-release', 'resumable' => true],
        ['transport' => 'instance-deploy-agent', 'name' => 'php.configure-runtime', 'resumable' => true],
        ['transport' => 'instance-deploy-agent', 'name' => 'php.converge-production-runtime', 'resumable' => true],
        ['transport' => 'instance-deploy-agent', 'name' => 'php.refresh-production-cache', 'resumable' => true],
        ...($rollback ? [] : [
            ['transport' => 'instance-deploy-agent', 'name' => 'instance.prepare-release', 'secrets' => ['git_read' => 'instance.deployment'], 'resumable' => false, 'uncertain_error_code' => 'deployment.interrupted'],
            ['transport' => 'instance-deploy-agent', 'name' => 'instance.resolve-development-target', 'resumable' => true, 'secrets' => ['git_read' => 'instance.deployment']],
            ['transport' => 'instance-deploy-agent', 'name' => 'instance.checkout-development-default', 'resumable' => true],
            ['transport' => 'instance-deploy-agent', 'name' => 'instance.run-deploy-step',
                'secrets' => ['script' => 'instance.deployment', 'secrets' => 'instance.deployment'], 'resumable' => false, 'uncertain_error_code' => 'deployment.interrupted'],
            ['transport' => 'instance-deploy-agent', 'name' => 'instance.run-development-step',
                'secrets' => ['script' => 'instance.deployment', 'secrets' => 'instance.deployment'], 'resumable' => false, 'uncertain_error_code' => 'deployment.interrupted'],
            ['transport' => 'instance-deploy-agent', 'name' => 'instance.prune-releases', 'resumable' => true],
            ['transport' => 'instance-deploy-agent', 'name' => 'tool.prune-vp-store', 'resumable' => true],
        ]),
        ['transport' => 'gateway', 'name' => 'instance.executor-runtime', 'resumable' => false, 'uncertain_error_code' => 'deployment.interrupted'],
        ['transport' => 'gateway', 'name' => 'instance.child-receipt', 'resumable' => true],
        ['transport' => 'gateway', 'name' => 'instance.deployment-budget', 'resumable' => true],
        ['transport' => 'gateway', 'name' => 'instance.deployment-receipt', 'resumable' => true],
    ],
    'children' => array_map(static fn (string $kind): array => ['kind' => $kind, 'node_from' => 'operation.node_id'],
        ['route.converge', 'instance.sync_environment', 'instance.prepare_vp_environment', 'process.install_instance_definitions',
            'schedule.install_instance_definitions', 'node.prepare_php_packages', 'process.restart_instance']),
    'completion_contract' => ['step' => 'verification', 'transport' => 'gateway', 'name' => 'instance.deployment-receipt', 'result' => []],
    'result_fields' => ['deployment_id', 'release', 'selected_release', 'commit', 'name', 'path',
        'release.name', 'release.path', 'release.commit', 'previous', 'previous.name', 'previous.path', 'previous.commit',
        ['name' => 'process_ids', 'type' => 'list', 'items' => 'integer', 'max' => 4096], 'ready', 'changed',
        'child_id', 'child_kind', 'child_status', 'published', 'complete', 'budget_seconds', 'timeout_seconds', 'command_timeout_seconds', 'exit_code', 'timed_out', 'output_tail', 'truncated', 'removed_count', 'in_use_count', 'error_code'],
];

$instanceExecutionKinds = [
    'instance.prepare_served_web_roots' => [
        'executor' => 'swamp', 'execution' => InstanceServedWebRootsExecution::class, 'completion' => InstanceServedWebRootsExecution::class,
        ...(OperationKinds::supports('children') ? ['admission' => InstanceExecutionChildAdmission::class] : []),
        'runs' => [
            ['transport' => 'instance-deploy-agent', 'name' => 'instance.prepare-served-web-roots', 'resumable' => true],
            ['transport' => 'gateway', 'name' => 'instance.web-roots-receipt', 'resumable' => true],
        ],
        'completion_contract' => ['step' => 'verification', 'transport' => 'gateway', 'name' => 'instance.web-roots-receipt', 'result' => ['confirmed' => true]],
        'result_fields' => ['instance_id', 'confirmed', 'no_op', 'prepared', 'changed', 'error_code'],
    ],
    'instance.repair_projection' => [
        'executor' => 'swamp', 'execution' => InstanceProjectionExecution::class, 'completion' => InstanceProjectionExecution::class,
        'runs' => [
            ['transport' => 'gateway', 'name' => 'instance.child-receipt', 'resumable' => true],
            ['transport' => 'gateway', 'name' => 'instance.projection-receipt', 'resumable' => true],
        ],
        'children' => [['kind' => 'route.converge', 'node_from' => 'operation.node_id']],
        'completion_contract' => ['step' => 'verification', 'transport' => 'gateway', 'name' => 'instance.projection-receipt',
            'result' => ['confirmed' => true], 'children_succeeded' => ['route.converge']],
        'result_fields' => ['instance_id', 'confirmed', 'child_id', 'child_kind', 'child_status', 'error_code'],
    ],
    'instance.deploy' => $deployment(false),
    'instance.rollback' => $deployment(true),
    'instance.prepare_php_runtime' => [
        ...(OperationKinds::supports('children') ? ['admission' => InstanceExecutionChildAdmission::class] : []),
        'children' => [['kind' => 'node.prepare_php_packages', 'node_from' => 'operation.node_id']],
        'executor' => 'swamp', 'execution' => InstancePhpRuntimeExecution::class, 'completion' => InstancePhpRuntimeExecution::class,
        'runs' => [
            ['transport' => 'gateway', 'name' => 'instance.child-receipt', 'resumable' => true],
            ['transport' => 'gateway', 'name' => 'instance.php-plan', 'resumable' => true],
            ['transport' => 'gateway', 'name' => 'instance.php-receipt', 'resumable' => true],
            ['transport' => 'instance-deploy-agent', 'name' => 'php.configure-runtime', 'resumable' => true],
            ['transport' => 'instance-deploy-agent', 'name' => 'php.converge-production-runtime', 'resumable' => true],
        ],
        'completion_contract' => ['step' => 'php_receipt', 'transport' => 'gateway', 'name' => 'instance.php-receipt', 'result' => ['confirmed' => true]],
        'result_fields' => ['child_id', 'child_kind', 'child_status', 'prepared', 'has_runtime',
            'published', 'changed', 'instance_id', 'confirmed', 'no_op', 'error_code'],
    ],
    'instance.prepare_vp_environment' => [
        ...(OperationKinds::supports('children') ? ['admission' => InstanceExecutionChildAdmission::class] : []),
        'children' => [['kind' => 'tool.manager_converge', 'node_from' => 'operation.node_id']],
        'executor' => 'swamp', 'execution' => InstanceVpEnvironmentExecution::class, 'completion' => InstanceVpEnvironmentExecution::class,
        'runs' => [
            ['transport' => 'gateway', 'name' => 'instance.child-receipt', 'resumable' => true],
            ['transport' => 'gateway', 'name' => 'instance.vp-plan', 'resumable' => true],
            ['transport' => 'gateway', 'name' => 'instance.vp-receipt', 'resumable' => true],
            ['transport' => 'instance-deploy-agent', 'name' => 'tool.inspect-vp-scope', 'resumable' => true],
            ['transport' => 'instance-deploy-agent', 'name' => 'tool.vp-version', 'resumable' => true],
            ['transport' => 'instance-deploy-agent', 'name' => 'tool.inspect-vp-store', 'resumable' => true],
            ['transport' => 'instance-deploy-agent', 'name' => 'tool.prune-vp-store', 'resumable' => true],
            ['transport' => 'instance-deploy-agent', 'name' => 'tool.install-vp-environment', 'resumable' => true, 'secrets' => ['npmrc' => 'instance.vp_environment']],
        ],
        'completion_contract' => ['step' => 'vp_receipt', 'transport' => 'gateway', 'name' => 'instance.vp-receipt', 'result' => ['confirmed' => true]],
        'result_fields' => ['child_id', 'child_kind', 'child_status', 'path', 'requirements', 'requirements.store', 'requirements.vp_version', 'requirements.node_version',
            'requirements.package_manager', 'requirements.package_manager_version', 'requirements.package_manager_hash', 'requirements.status',
            'binary', 'version', 'installed', 'confirmed', 'instance_id', 'no_op', 'vp_environment',
            'vp_environment.store', 'vp_environment.node_version', 'vp_environment.package_manager',
            'vp_environment.package_manager_version', 'vp_environment.package_manager_hash', 'error_code'],
    ],
    'instance.run_steps' => [
        ...(OperationKinds::supports('children') ? ['admission' => InstanceExecutionChildAdmission::class] : []),
        'executor' => 'swamp',
        'result_fields' => ['instance_id', 'outcome', 'failed_step', 'error_code', 'no_op'],
    ],

];

if (! OperationKinds::supports('children')) {
    foreach (['instance.deploy', 'instance.rollback', 'instance.prepare_vp_environment', 'instance.prepare_php_runtime', 'instance.repair_projection', 'instance.prepare_served_web_roots'] as $kind) {
        unset($instanceExecutionKinds[$kind]);
    }
}

return $instanceExecutionKinds;
