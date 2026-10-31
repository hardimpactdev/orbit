<?php

declare(strict_types=1);

use App\Domain\Schedules\ScheduleControlProjection;
use App\Domain\Schedules\ScheduleInstanceOperation;

return [
    'schedule.install_instance_definitions' => ['workflow' => 'operation-schedule-install-instance-definitions', 'projection' => ScheduleInstanceOperation::class, 'private_material' => ['installations' => 'schedule.instance_installations']],
    'schedule.create' => ['workflow' => 'operation-schedule-create', 'workflow_inputs' => true, 'terminal_result' => ['job' => 'schedule', 'step' => 'result'], 'projection' => ScheduleControlProjection::class],
    'schedule.enable' => ['workflow' => 'operation-schedule-control', 'workflow_inputs' => true, 'terminal_result' => ['job' => 'schedule', 'step' => 'result'], 'projection' => ScheduleControlProjection::class],
    'schedule.run' => ['workflow' => 'operation-schedule-run', 'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^schedule-run-operation-[1-9][0-9]*$']], 'workflow_inputs' => true, 'terminal_result' => ['job' => 'run', 'step' => 'result'], 'projection' => ScheduleControlProjection::class],
    'schedule.destroy' => ['workflow' => 'operation-schedule-destroy', 'workflow_inputs' => true, 'terminal_result' => ['job' => 'schedule', 'step' => 'result'], 'projection' => ScheduleControlProjection::class],
];
