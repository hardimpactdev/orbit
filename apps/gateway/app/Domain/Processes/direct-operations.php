<?php

declare(strict_types=1);

use App\Domain\Processes\ProcessControlProjection;
use App\Domain\Processes\ProcessInstanceOperation;
use App\Infrastructure\Processes\ContainerProcessObservation;

$control = ['workflow' => 'operation-process-control', 'workflow_inputs' => true, 'terminal_result' => ['job' => 'runtime', 'step' => 'Verify the state', 'native' => true], 'projection' => ProcessControlProjection::class];

return [
    // The status read of a container Process: its unit's state, which lists show until the next read
    // (docs/reference/development-containers.md#processes). Its journal lines stay in the vault.
    'process.inspect' => ['workflow' => 'operation-process-container', 'workflow_inputs' => true,
        'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^nckrtl-process-command-assets-[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}-inspection-inspection-inspection-[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}-value$']],
        'terminal_result' => ['job' => 'process', 'step' => 'Read Process result', 'native' => true,
            'captures' => ['state' => ['job' => 'process', 'step' => 'Read Process result', 'field' => 'state']]],
        'projection' => ContainerProcessObservation::class],
    'process.install_instance_definitions' => ['workflow' => 'operation-process-install-instance-definitions', 'workflow_inputs' => true, 'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^process-instance-operation-[1-9][0-9]*-member-[1-9][0-9]*$']], 'terminal_result' => ['job' => 'instance', 'step' => 'result'], 'projection' => ProcessInstanceOperation::class],
    'process.update' => ['workflow' => 'operation-process-create', 'workflow_inputs' => true, 'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^process-update-operation-[1-9][0-9]*$']], 'terminal_result' => ['job' => 'process', 'step' => 'Verify it runs', 'native' => true], 'projection' => ProcessControlProjection::class],
    'process.create' => ['workflow' => 'operation-process-create', 'workflow_inputs' => true, 'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^process-create-operation-[1-9][0-9]*$']], 'terminal_result' => ['job' => 'process', 'step' => 'Verify it runs', 'native' => true], 'projection' => ProcessControlProjection::class],
    'process.destroy' => ['workflow' => 'operation-process-destroy', 'workflow_inputs' => true, 'terminal_result' => ['job' => 'process', 'step' => 'Verify it is gone', 'native' => true], 'projection' => ProcessControlProjection::class],
    'process.restart' => ['workflow' => 'operation-process-control'] + $control,
    'process.start' => ['workflow' => 'operation-process-start'] + $control,
    'process.stop' => ['workflow' => 'operation-process-stop'] + $control,
];
