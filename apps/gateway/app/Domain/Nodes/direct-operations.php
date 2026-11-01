<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeAgentRekeyProjection;
use App\Domain\Nodes\NodeConvergeProjection;
use App\Domain\Nodes\NodeNetworkProjection;
use App\Domain\Nodes\NodeRemovalProjection;
use App\Domain\Nodes\NodeRoleDirectProjection;
use App\Domain\Nodes\NodeRoleParentProjection;
use App\Domain\Nodes\NodeRoleRemovalProjection;
use App\Domain\Nodes\NodeSettingsProjection;
use App\Domain\Nodes\NodeSshAccessProjection;
use App\Domain\Nodes\NodeWireGuardHubProjection;
use App\Domain\Nodes\WorkerKnownHostsProjection;

return ['node.worker_known_hosts' => [
    'workflow' => 'operation-node-worker-known-hosts',
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'known_hosts', 'step' => 'write'],
    'projection' => WorkerKnownHostsProjection::class,
], 'node.ssh_access_converge' => [
    'workflow' => 'operation-node-ssh-access-converge',
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^bootstrap-identity-node-14$']],
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'ssh_access', 'step' => 'result'],
    'projection' => NodeSshAccessProjection::class,
], 'node.wireguard_hub_converge' => [
    'workflow' => 'operation-node-wireguard-hub-converge',
    'projection' => NodeWireGuardHubProjection::class,
    'private_material' => ['configuration' => 'node.vpn_configuration'],
], 'node.remove' => [
    'workflow' => 'operation-node-remove', 'projection' => NodeRemovalProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'remove', 'step' => 'result'],
], ...(require __DIR__.'/bootstrap-direct-operations.php'), 'node.network_converge' => [
    'workflow' => 'operation-node-network-converge',
    'projection' => NodeNetworkProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'network', 'step' => 'result'],
], 'node.agent_rekey' => [
    'workflow' => 'operation-node-agent-rekey',
    'projection' => NodeAgentRekeyProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'result', 'step' => 'result'],
], 'node.settings' => [
    'workflow' => 'operation-node-settings',
    'projection' => NodeSettingsProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'result', 'step' => 'result'],
], 'node.converge' => [
    'workflow' => 'operation-node-converge-direct',
    'projection' => NodeConvergeProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'result', 'step' => 'result'],
], 'node.role_add' => [
    'workflow' => 'operation-node-role-add-direct',
    'projection' => NodeRoleParentProjection::class,
    'private_material' => ['secret_hash' => 'node.agent_secret_hash', 'runtime_material' => 'node.role_runtime', 'metrics_runtime' => 'metrics.fleet_runtime'],
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'result', 'step' => 'result', 'captures' => [
        'target' => ['job' => 'target', 'step' => 'forward_role_result'],
        'target_rollback' => ['job' => 'rollback', 'step' => 'target_rollback', 'field' => 'result'],
    ]],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^node-role-[0-9]+-(observer|runtime_material)$']],
], 'node.role_converge' => [
    'workflow' => 'operation-node-role-converge-209',
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'result', 'step' => 'result'],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^node-role-[0-9]+-(observer|runtime_material)$']],
    'projection' => NodeRoleDirectProjection::class,
    'private_material' => ['secret_hash' => 'node.agent_secret_hash', 'runtime_material' => 'node.role_runtime', 'metrics_runtime' => 'metrics.fleet_runtime'],
], 'node.role_relocate' => [
    'workflow' => 'operation-node-role-relocate-direct',
    'projection' => NodeRoleParentProjection::class,
    'private_material' => ['secret_hash' => 'node.agent_secret_hash', 'runtime_material' => 'node.role_runtime', 'metrics_runtime' => 'metrics.fleet_runtime'],
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'result', 'step' => 'result', 'captures' => [
        'target' => ['job' => 'target', 'step' => 'forward_role_result'],
        'source_cleanup' => ['job' => 'source', 'step' => 'source_cleanup', 'field' => 'result'],
    ]],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^node-role-[0-9]+-(observer|runtime_material)$']],
], 'node.role_remove' => [
    'workflow' => 'operation-node-role-remove-direct',
    'projection' => NodeRoleRemovalProjection::class,
    'private_material' => ['metrics_runtime' => 'metrics.fleet_runtime'],
]];
