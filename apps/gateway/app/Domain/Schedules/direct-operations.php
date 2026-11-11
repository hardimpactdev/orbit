<?php

declare(strict_types=1);

use App\Domain\Schedules\ScheduleControlProjection;
use App\Domain\Schedules\ScheduleInstanceOperation;

return [
    'schedule.install_instance_definitions' => ['workflow' => 'operation-schedule-install-instance-definitions', 'workflow_inputs' => true, 'terminal_result' => ['job' => 'instance', 'step' => 'Check the members', 'native' => true], 'projection' => ScheduleInstanceOperation::class],
    'schedule.create' => ['workflow' => 'operation-schedule-create', 'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^schedule-command-[0-9a-f-]{36}$']], 'workflow_inputs' => true, 'terminal_result' => ['job' => 'schedule', 'step' => 'Check the installation', 'native' => true], 'projection' => ScheduleControlProjection::class],
    'schedule.enable' => ['workflow' => 'operation-schedule-control', 'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^schedule-command-[0-9a-f-]{36}$']], 'workflow_inputs' => true, 'terminal_result' => ['job' => 'schedule', 'step' => 'Check the timer', 'native' => true], 'projection' => ScheduleControlProjection::class],
    // Record-only (R1): the Gateway removes the trigger on serve; the declared workflow never starts.
    'schedule.disable' => ['workflow' => 'operation-schedule-control', 'workflow_inputs' => true, 'terminal_result' => ['job' => 'schedule', 'step' => 'result'], 'projection' => ScheduleControlProjection::class],
    // This family also owns the existing vault result grant for migrated Schedule journal reads.
    'schedule.run' => ['workflow' => 'task-command-run', 'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^schedule-run-operation-[1-9][0-9]*$'], ['vault' => 'orbit-control-inputs', 'key_pattern' => '^nckrtl-process-command-assets-[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}-inspection-inspection-inspection-[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}-value$']], 'workflow_inputs' => true, 'terminal_result' => ['job' => 'run', 'step' => 'Run the command', 'native' => true], 'projection' => ScheduleControlProjection::class],
    'schedule.destroy' => ['workflow' => 'operation-schedule-destroy', 'workflow_inputs' => true, 'terminal_result' => ['job' => 'schedule', 'step' => 'Check the removal', 'native' => true], 'projection' => ScheduleControlProjection::class],
];
