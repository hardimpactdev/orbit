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
    'schedule.run' => $control,
    'schedule.destroy' => $control,
];
