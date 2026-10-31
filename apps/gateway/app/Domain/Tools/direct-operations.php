<?php

declare(strict_types=1);

use App\Domain\Tools\ToolIntentProjection;

$intent = ['workflow' => 'operation-tool-direct', 'projection' => ToolIntentProjection::class];

return [
    'tool.install' => [...$intent, 'workflow' => 'operation-tool-install-direct'],
    'tool.update' => $intent,
    'tool.remove' => $intent,
    'tool.adopt' => $intent,
    'tool.manager_converge' => $intent,
];
