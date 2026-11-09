<?php

declare(strict_types=1);

use App\Domain\Nodes\NativeNodeEnrollmentProjection;
use App\Domain\Nodes\NodeAgentRekeyProjection;
use App\Domain\Nodes\NodeConvergeProjection;
use App\Domain\Nodes\NodeRemovalProjection;
use App\Domain\Nodes\NodeRoleDirectProjection;
use App\Domain\Nodes\NodeRoleRelocateProjection;
use App\Domain\Nodes\NodeRoleRemovalProjection;
use App\Domain\Nodes\NodeSettingsProjection;
use App\Domain\Nodes\NodeSshAccessProjection;
use App\Domain\Nodes\WorkerKnownHostsProjection;

return ['node.add' => [
    'workflow' => 'operation-node-add',
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'node', 'step' => 'Check the Node', 'native' => true],
    'projection' => NativeNodeEnrollmentProjection::class,
    'execution_copies' => [
        ['vault' => 'orbit-control-inputs', 'key_pattern' => '^bootstrap-identity-node-[1-9][0-9]*$'],
        ['vault' => 'orbit-control-inputs', 'key_pattern' => '^agent-secret-node-[1-9][0-9]*$'],
        ['vault' => 'orbit-control-inputs', 'key_pattern' => '^node-role-operation-[1-9][0-9]*-[a-z-]+$'],
        ['vault' => 'orbit-control-inputs', 'key_pattern' => '^process-parent-operation-[1-9][0-9]*-node-[1-9][0-9]*-member-[1-9][0-9]*$'],
    ],
], 'node.worker_known_hosts' => [
    'workflow' => 'operation-node-worker-known-hosts',
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'known_hosts', 'step' => 'Write known hosts', 'native' => true],
    'projection' => WorkerKnownHostsProjection::class,
], 'node.ssh_access_converge' => [
    'workflow' => 'operation-node-ssh-access-converge',
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'ssh_access', 'step' => 'Log in with Swamp identity', 'native' => true],
    'projection' => NodeSshAccessProjection::class,
], 'node.remove' => [
    'workflow' => 'operation-node-remove', 'projection' => NodeRemovalProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'remove', 'step' => 'Confirm Node removal', 'native' => true],
], 'node.agent_rekey' => [
    'workflow' => 'operation-node-agent-rekey',
    'projection' => NodeAgentRekeyProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'rekey', 'step' => 'Prove the new key', 'native' => true],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^node-agent-rekey-[1-9][0-9]*-[1-9][0-9]*$']],
], 'node.update' => [
    'workflow' => 'operation-node-update',
    'projection' => NodeSettingsProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'settings', 'step' => 'Check owner and mode', 'alternatives' => ['Withdraw task host input'], 'native' => true],
], 'node.converge' => [
    'workflow' => 'operation-node-converge',
    'projection' => NodeConvergeProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'converge', 'step' => 'Report Node converge', 'data' => 'node-converge-result'],
    'execution_copies' => [
        ['vault' => 'orbit-control-inputs', 'key_pattern' => '^node-converge-operation-[1-9][0-9]*-agent-verifier$'],
        ['vault' => 'orbit-control-inputs', 'key_pattern' => '^node-role-operation-[1-9][0-9]*-[a-z-]+$'],
    ],
], 'node.role_add' => [
    'workflow' => 'operation-node-role-add',
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'role', 'step' => 'Check the role', 'native' => true],
    'execution_copies' => [
        ['vault' => 'orbit-control-inputs', 'key_pattern' => '^node-role-operation-[1-9][0-9]*-[a-z-]+$'],
        ['vault' => 'orbit-control-inputs', 'key_pattern' => '^process-parent-operation-[1-9][0-9]*-node-[1-9][0-9]*-member-[1-9][0-9]*$'],
    ],
    'projection' => NodeRoleDirectProjection::class,
    'private_material' => ['secret_hash' => 'node.agent_secret_hash', 'runtime_material' => 'node.role_runtime', 'metrics_runtime' => 'metrics.fleet_runtime'],
], 'node.role_converge' => [
    'workflow' => 'operation-node-role-add',
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'role', 'step' => 'Check the role', 'native' => true],
    'execution_copies' => [
        ['vault' => 'orbit-control-inputs', 'key_pattern' => '^node-role-operation-[1-9][0-9]*-[a-z-]+$'],
        ['vault' => 'orbit-control-inputs', 'key_pattern' => '^process-parent-operation-[1-9][0-9]*-node-[1-9][0-9]*-member-[1-9][0-9]*$'],
    ],
    'projection' => NodeRoleDirectProjection::class,
    'private_material' => ['secret_hash' => 'node.agent_secret_hash', 'runtime_material' => 'node.role_runtime', 'metrics_runtime' => 'metrics.fleet_runtime'],
], 'node.role_relocate' => [
    'workflow' => 'operation-node-role-relocate',
    'projection' => NodeRoleRelocateProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'confirm', 'step' => 'Confirm the move', 'native' => true],
    'execution_copies' => [
        ['vault' => 'orbit-control-inputs', 'key_pattern' => '^metrics-role-operation-[1-9][0-9]*$'],
        ['vault' => 'orbit-control-inputs', 'key_pattern' => '^node-role-relocate-operation-[1-9][0-9]*$'],
    ],
], 'node.role_remove' => [
    'workflow' => 'operation-node-role-remove',
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'cleanup', 'step' => 'Remove the run folder', 'native' => true],
    'projection' => NodeRoleRemovalProjection::class,
]];
