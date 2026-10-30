<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeSettingsProjection;

return ['node.settings' => [
    'workflow' => 'operation-node-settings',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'node_settings_writer',
    'writer_scope' => ['node_id' => 209, 'parent_operation_id' => null],
    'projection' => NodeSettingsProjection::class,
]];
