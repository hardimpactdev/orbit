<?php

declare(strict_types=1);

use App\Domain\Processes\ProcessControlProjection;

$control = [
    'workflow' => 'operation-process-control',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'process_control_writer',
    'projection' => ProcessControlProjection::class,
    'writer_scope' => ['vite' => null, 'parent_operation_id' => null],
];

return [
    ...array_fill_keys(['process.start', 'process.restart', 'process.destroy'], $control),
    'process.stop' => [...$control, 'writer_scope' => []],
];
