<?php

declare(strict_types=1);

use App\Domain\Projects\ProjectMaintenanceProjection;

return [
    'project.observe' => ['workflow' => 'project-observe', 'workflow_inputs' => true,
        'terminal_result' => ['job' => 'observe', 'step' => 'result'], 'projection' => ProjectMaintenanceProjection::class,
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^project-dependency-read-[1-9][0-9]*$'],
            ['vault' => 'orbit-control-inputs', 'key_pattern' => '^project-dependency-auth-[1-9][0-9]*$']]],
    'project.detect' => ['workflow' => 'project-detect', 'workflow_inputs' => true,
        'terminal_result' => ['job' => 'detect', 'step' => 'result'], 'projection' => ProjectMaintenanceProjection::class,
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^project-dependency-read-[1-9][0-9]*$'],
            ['vault' => 'orbit-control-inputs', 'key_pattern' => '^project-dependency-auth-[1-9][0-9]*$']]],
    'project.routine' => ['workflow' => 'project-routine', 'workflow_inputs' => true,
        'terminal_result' => ['job' => 'routine', 'step' => 'result'], 'projection' => ProjectMaintenanceProjection::class],
];
