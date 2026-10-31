<?php

declare(strict_types=1);

use App\Domain\Schedules\ScheduleControlProjection;
use App\Domain\Schedules\ScheduleInstanceOperation;

$control = [
    'workflow' => 'operation-schedule-control',
    'projection' => ScheduleControlProjection::class,
    'private_material' => ['command' => 'schedule.command'],
];

return [
    'schedule.install_instance_definitions' => ['workflow' => 'operation-schedule-install-instance-definitions', 'projection' => ScheduleInstanceOperation::class, 'private_material' => ['installations' => 'schedule.instance_installations']],
    'schedule.create' => [...$control, 'workflow' => 'operation-schedule-create'],
    'schedule.enable' => $control,
    'schedule.run' => ['workflow' => 'operation-schedule-run', 'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^schedule-run-operation-[1-9][0-9]*$']], 'workflow_inputs' => true, 'terminal_result' => ['job' => 'run', 'step' => 'result'], 'projection' => ScheduleControlProjection::class],
    'schedule.destroy' => $control,
];
