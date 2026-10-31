<?php

declare(strict_types=1);

use App\Domain\Schedules\ScheduleControlProjection;

$control = [
    'workflow' => 'operation-schedule-control',
    'projection' => ScheduleControlProjection::class,
    'private_material' => ['command' => 'schedule.command'],
];

return [
    'schedule.create' => [...$control, 'workflow' => 'operation-schedule-create'],
    'schedule.enable' => $control,
    'schedule.run' => $control,
    'schedule.destroy' => $control,
];
