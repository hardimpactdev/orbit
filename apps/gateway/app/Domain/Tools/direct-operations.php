<?php

declare(strict_types=1);

use App\Domain\Tools\ToolIntentProjection;

$intent = ['workflow' => 'operation-tool-write-209', 'projection' => ToolIntentProjection::class, 'workflow_inputs' => true, 'terminal_result' => ['job' => 'tools', 'step' => 'result']];

return [
    'tool.install' => $intent,
    'tool.update' => $intent,
    'tool.remove' => [...$intent, 'workflow' => 'operation-tool-remove-209', 'workflow_inputs' => true, 'terminal_result' => ['job' => 'tools', 'step' => 'result']],
    'tool.adopt' => [...$intent, 'workflow' => 'operation-tool-adopt-209', 'workflow_inputs' => true, 'terminal_result' => ['job' => 'tools', 'step' => 'result']],
    'tool.manager_converge' => $intent,
];
