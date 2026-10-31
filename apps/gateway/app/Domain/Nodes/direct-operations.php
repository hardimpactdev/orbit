<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeConvergeProjection;
use App\Domain\Nodes\NodeRoleDirectProjection;
use App\Domain\Nodes\NodeSettingsProjection;

return ['node.settings' => [
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
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'node_role_writer',
    'writer_scope' => ['parent_operation_id' => null, 'native_role' => true],
    'projection' => NodeRoleDirectProjection::class,
    'private_material' => ['secret_hash' => 'node.agent_secret_hash'],
]];
