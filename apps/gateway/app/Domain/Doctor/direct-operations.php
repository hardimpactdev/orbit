<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorProjection;
use App\Domain\Observation\ObservationMaintenanceProjection;

return ['doctor.inspect' => [
    'workflow' => 'operation-doctor-inspect',
    'projection' => DoctorProjection::class,
    'private_material' => ['database_servers' => 'doctor.database_servers', 'schedules' => 'doctor.schedules'],
], 'observation.maintain' => [
    'workflow' => 'operation-observation-maintain',
    'projection' => ObservationMaintenanceProjection::class,
    'private_material' => ['queue_redis' => 'observation.queue_redis'],
]];
