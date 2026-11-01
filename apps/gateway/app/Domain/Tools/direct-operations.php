<?php

declare(strict_types=1);

use App\Domain\Tools\ToolIntentProjection;

$intent = ['workflow' => 'operation-tool-direct', 'projection' => ToolIntentProjection::class];

return [
    'tool.install' => [...$intent, 'workflow' => 'operation-tool-install-direct'],
    'tool.update' => $intent,
    'tool.remove' => [...$intent, 'workflow' => 'operation-tool-remove-209', 'workflow_inputs' => true, 'terminal_result' => ['job' => 'tools', 'step' => 'result']],
    'tool.adopt' => [...$intent, 'workflow' => 'operation-tool-adopt-209', 'workflow_inputs' => true, 'terminal_result' => ['job' => 'tools', 'step' => 'result']],
    'tool.manager_converge' => $intent,
];
