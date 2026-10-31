<?php

declare(strict_types=1);

use App\Domain\Processes\ProcessControlProjection;
use App\Domain\Processes\ProcessInstanceOperation;

$control = [
    'workflow' => 'operation-process-control',
    'projection' => ProcessControlProjection::class,
];

return [
    'process.restart_instance' => ['workflow' => 'operation-process-restart-instance', 'projection' => ProcessInstanceOperation::class],
    'process.update' => [...$control, 'workflow' => 'operation-process-update', 'private_material' => ['installation' => 'process.installation']],
    'process.create' => [...$control, 'private_material' => ['installation' => 'process.installation']],
    'process.destroy' => [...$control],
    'process.restart' => [...$control],
    'process.start' => [...$control],
    'process.stop' => [...$control],
];
