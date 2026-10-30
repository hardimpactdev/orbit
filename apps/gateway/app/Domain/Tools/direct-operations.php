<?php

declare(strict_types=1);

use App\Domain\Tools\ToolIntentProjection;

$intent = [
    'workflow' => 'operation-tool-direct',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'tool_writer',
    'projection' => ToolIntentProjection::class,
];

return [
    'tool.install' => [...$intent, 'writer_scope' => ['node_id' => 209, 'parent_operation_id' => null]],
    'tool.update' => [...$intent, 'writer_scope' => ['node_id' => 209, 'parent_operation_id' => null]],
    'tool.remove' => [...$intent, 'writer_scope' => ['node_id' => 209, 'parent_operation_id' => null]],
    'tool.adopt' => [...$intent, 'writer_scope' => ['manager' => 'apt', 'node_id' => 209, 'parent_operation_id' => null, 'tool_status' => 'installed']],
    'tool.manager_converge' => [...$intent, 'writer_scope' => ['manager' => 'apt', 'node_id' => 209, 'parent_operation_id' => null]],
];
