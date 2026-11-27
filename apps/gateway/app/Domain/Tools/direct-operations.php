<?php

declare(strict_types=1);

use App\Domain\Tools\AptRepositoryProjection;
use App\Domain\Tools\ToolConvergeProjection;
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
    'tool.converge' => ['workflow' => 'operation-tool-converge', 'projection' => ToolConvergeProjection::class, 'workflow_inputs' => true,
        'terminal_result' => ['job' => 'tools', 'step' => 'Report Tool converge']],
    // An apt repository's own add and removal: the Tools phase with only that repository (docs/reference/tools.md#apt-repositories).
    'tool.apt_repository_add' => ['workflow' => 'operation-tool-converge', 'projection' => AptRepositoryProjection::class, 'workflow_inputs' => true,
        'terminal_result' => ['job' => 'tools', 'step' => 'Report Tool converge']],
    'tool.apt_repository_remove' => ['workflow' => 'operation-tool-converge', 'projection' => AptRepositoryProjection::class, 'workflow_inputs' => true,
        'terminal_result' => ['job' => 'tools', 'step' => 'Report Tool converge']],
];
