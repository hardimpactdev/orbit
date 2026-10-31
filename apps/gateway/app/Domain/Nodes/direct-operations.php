<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeAgentRekeyProjection;
use App\Domain\Nodes\NodeConvergeProjection;
use App\Domain\Nodes\NodeSettingsProjection;

return ['node.agent_rekey' => [
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
]];
