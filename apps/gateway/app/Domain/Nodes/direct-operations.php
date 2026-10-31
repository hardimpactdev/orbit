<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeAgentRekeyProjection;
use App\Domain\Nodes\NodeConvergeProjection;
use App\Domain\Nodes\NodeNetworkProjection;
use App\Domain\Nodes\NodeRemovalEnvironmentSource;
use App\Domain\Nodes\NodeRemovalProjection;
use App\Domain\Nodes\NodeRetargetProjection;
use App\Domain\Nodes\NodeRoleDirectProjection;
use App\Domain\Nodes\NodeRoleRemovalProjection;
use App\Domain\Nodes\NodeSettingsProjection;
use App\Domain\Nodes\NodeWireGuardHubProjection;
use App\Domain\Nodes\NodeWireGuardKeyProjection;

return ['node.retarget' => [
    'workflow' => 'operation-node-retarget',
    'workflow_inputs' => true, 'terminal_result' => ['job' => 'retarget', 'step' => 'terminal'],
    'projection' => NodeRetargetProjection::class,
], 'node.wireguard_key' => [
    'workflow' => 'operation-node-wireguard-key',
    'workflow_inputs' => true, 'terminal_result' => ['job' => 'hub', 'step' => 'terminal'],
    'projection' => NodeWireGuardKeyProjection::class,
], 'node.wireguard_hub_converge' => [
    'workflow' => 'operation-node-wireguard-hub-converge',
    'workflow_inputs' => true, 'terminal_result' => ['job' => 'hub', 'step' => 'terminal'],
    'projection' => NodeWireGuardHubProjection::class,
], 'node.remove' => [
    'workflow_inputs' => true,
    'workflow' => 'operation-node-remove', 'projection' => NodeRemovalProjection::class,
    'private_material' => ['bootstrap_identity' => 'node.bootstrap_ssh_identity', 'configuration' => 'node.vpn_configuration', 'metrics_runtime' => 'metrics.fleet_runtime',
        ...NodeRemovalEnvironmentSource::privateMaterial()],
], ...(require __DIR__.'/bootstrap-direct-operations.php'), 'node.network_converge' => [
    'workflow_inputs' => true,
    'workflow' => 'operation-node-network-converge',
    'projection' => NodeNetworkProjection::class,
    'private_material' => ['configuration' => 'node.vpn_configuration'],
], 'node.agent_rekey' => [
    'workflow' => 'operation-node-agent-rekey',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'node_agent_rekey_writer',
    'writer_scope' => ['parent_operation_id' => null],
    'projection' => NodeAgentRekeyProjection::class,
    'secret_outputs' => ['secret_hash' => 'node.agent_secret_hash'],
], 'node.settings' => [
    'workflow' => 'operation-node-settings',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'node_settings_writer',
    'projection' => NodeSettingsProjection::class,
], 'node.converge' => [
    'workflow' => 'operation-node-converge-direct',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'node_converge_writer',
    'writer_scope' => ['parent_operation_id' => null, 'native_footprint' => true],
    'projection' => NodeConvergeProjection::class,
    'private_material' => ['secret_hash' => 'node.agent_secret_hash', 'worker_credentials' => 'node.worker_credentials'],
], 'node.role_add' => [
    'workflow' => 'operation-node-role-add-direct',
    'projection' => NodeRoleDirectProjection::class,
    'private_material' => ['secret_hash' => 'node.agent_secret_hash', 'runtime_material' => 'node.role_runtime', 'metrics_runtime' => 'metrics.fleet_runtime'],
], 'node.role_converge' => [
    'workflow' => 'operation-node-role-converge-direct',
    'projection' => NodeRoleDirectProjection::class,
    'private_material' => ['secret_hash' => 'node.agent_secret_hash', 'runtime_material' => 'node.role_runtime', 'metrics_runtime' => 'metrics.fleet_runtime'],
], 'node.role_relocate' => [
    'workflow' => 'operation-node-role-relocate-direct',
    'projection' => NodeRoleDirectProjection::class,
    'private_material' => ['secret_hash' => 'node.agent_secret_hash', 'runtime_material' => 'node.role_runtime', 'metrics_runtime' => 'metrics.fleet_runtime'],
], 'node.role_remove' => [
    'workflow' => 'operation-node-role-remove-direct',
    'projection' => NodeRoleRemovalProjection::class,
    'private_material' => ['metrics_runtime' => 'metrics.fleet_runtime'],
]];
