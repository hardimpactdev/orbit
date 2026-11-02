<?php

declare(strict_types=1);

use App\Domain\Tools\ToolIntentProjection;

$terminal = ['job' => 'tools', 'step' => 'Assert package state', 'native' => true, 'captures' => [
    'decision' => ['job' => 'tools', 'step' => 'Choose package action'],
    'observation' => ['job' => 'tools', 'step' => 'Decode package state'],
]];

$intent = ['workflow' => 'operation-tool-write-209', 'projection' => ToolIntentProjection::class, 'workflow_inputs' => true, 'terminal_result' => $terminal];

return [
    'tool.install' => $intent,
    'tool.update' => $intent,
    'tool.remove' => [...$intent, 'workflow' => 'operation-tool-remove-209'],
    'tool.adopt' => [...$intent, 'workflow' => 'operation-tool-adopt-209', 'workflow_inputs' => true, 'terminal_result' => ['job' => 'tools', 'step' => 'result']],
];
