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
    'tool.install' => $intent,
    'tool.update' => $intent,
    'tool.remove' => $intent,
    'tool.adopt' => $intent,
    'tool.manager_converge' => $intent,
];
