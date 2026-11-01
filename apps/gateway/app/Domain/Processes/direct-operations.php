<?php

declare(strict_types=1);

use App\Domain\Processes\HorizonOperation;
use App\Domain\Processes\ProcessControlProjection;
use App\Domain\Processes\ProcessInstanceOperation;

$control = [
    'workflow' => 'operation-process-control',
    'projection' => ProcessControlProjection::class,
];

return [
    'process.horizon_handoff' => ['workflow' => 'operation-process-horizon-handoff', 'workflow_inputs' => true, 'terminal_result' => ['job' => 'horizon', 'step' => 'result'], 'projection' => HorizonOperation::class],
    'process.install_instance_definitions' => ['workflow' => 'operation-process-install-instance-definitions', 'projection' => ProcessInstanceOperation::class, 'private_material' => ['installations' => 'process.instance_installations']],
    'process.restart_instance' => ['workflow' => 'operation-process-restart-instance', 'projection' => ProcessInstanceOperation::class],
    'process.update' => [...$control, 'workflow' => 'operation-process-update', 'private_material' => ['installation' => 'process.installation']],
    'process.create' => ['workflow' => 'operation-process-create', 'workflow_inputs' => true, 'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^process-create-operation-[1-9][0-9]*$']], 'terminal_result' => ['job' => 'process', 'step' => 'result'], 'projection' => ProcessControlProjection::class],
    'process.destroy' => ['workflow' => 'operation-process-destroy', 'workflow_inputs' => true, 'terminal_result' => ['job' => 'process', 'step' => 'result'], 'projection' => ProcessControlProjection::class],
    'process.restart' => [...$control],
    'process.start' => [...$control],
    'process.stop' => [...$control, 'workflow' => 'operation-process-stop', 'workflow_inputs' => true, 'terminal_result' => ['job' => 'runtime', 'step' => 'verify']],
];
