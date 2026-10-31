<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorProjection;

return ['doctor.inspect' => [
    'workflow' => 'operation-doctor-inspect',
    'projection' => DoctorProjection::class,
    'private_material' => ['database_servers' => 'doctor.database_servers', 'schedules' => 'doctor.schedules'],
]];
