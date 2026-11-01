<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorProjection;
use App\Domain\Observation\ObservationMaintenanceProjection;

return ['doctor.inspect' => [
    'workflow' => 'operation-doctor-inspect',
    'projection' => DoctorProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'report', 'step' => 'result'],
], 'observation.maintain' => [
    'workflow' => 'operation-observation-maintain',
    'projection' => ObservationMaintenanceProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'report', 'step' => 'result'],
]];
