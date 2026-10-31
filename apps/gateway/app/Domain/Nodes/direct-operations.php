<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeConvergeProjection;
use App\Domain\Nodes\NodeSettingsProjection;

return ['node.settings' => [
    'workflow' => 'operation-node-settings',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'node_settings_writer',
    'writer_scope' => ['node_id' => 209, 'parent_operation_id' => null],
    'projection' => NodeSettingsProjection::class,
], 'node.converge' => [
    'workflow' => 'operation-node-converge-direct',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'node_converge_writer',
    'writer_scope' => ['parent_operation_id' => null, 'native_footprint' => true],
    'projection' => NodeConvergeProjection::class,
    'private_material' => ['secret_hash' => 'node.agent_secret_hash', 'worker_credentials' => 'node.worker_credentials'],
]];
