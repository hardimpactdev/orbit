<?php

declare(strict_types=1);

use App\Domain\Schedules\ScheduleControlProjection;
use App\Domain\Schedules\ScheduleInstanceOperation;

return [
    'schedule.install_instance_definitions' => ['workflow' => 'operation-schedule-install-instance-definitions', 'workflow_inputs' => true, 'terminal_result' => ['job' => 'instance', 'step' => 'result'], 'projection' => ScheduleInstanceOperation::class],
    'schedule.create' => ['workflow' => 'operation-schedule-create', 'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^schedule-command-[0-9a-f-]{36}$']], 'workflow_inputs' => true, 'terminal_result' => ['job' => 'schedule', 'step' => 'result'], 'projection' => ScheduleControlProjection::class],
    'schedule.enable' => ['workflow' => 'operation-schedule-control', 'workflow_inputs' => true, 'terminal_result' => ['job' => 'schedule', 'step' => 'result'], 'projection' => ScheduleControlProjection::class],
    // Record-only (R1): the Gateway removes the trigger on serve; the declared workflow never starts.
    'schedule.disable' => ['workflow' => 'operation-schedule-control', 'workflow_inputs' => true, 'terminal_result' => ['job' => 'schedule', 'step' => 'result'], 'projection' => ScheduleControlProjection::class],
    'schedule.run' => ['workflow' => 'task-command-run', 'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^schedule-run-operation-[1-9][0-9]*$']], 'workflow_inputs' => true, 'terminal_result' => ['job' => 'run', 'step' => 'Report the result'], 'projection' => ScheduleControlProjection::class],
    'schedule.destroy' => ['workflow' => 'operation-schedule-destroy', 'workflow_inputs' => true, 'terminal_result' => ['job' => 'schedule', 'step' => 'result'], 'projection' => ScheduleControlProjection::class],
];
