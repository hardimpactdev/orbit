<?php

declare(strict_types=1);

use App\Domain\Schedules\ScheduleControlProjection;

$control = [
    'workflow' => 'operation-schedule-control',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'schedule_control_writer',
    'projection' => ScheduleControlProjection::class,
];

return [
    'schedule.enable' => $control,
    'schedule.run' => $control,
];
