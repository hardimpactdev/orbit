<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorProjection;

return ['doctor.inspect' => [
    'workflow' => 'operation-doctor-inspect',
    'projection' => DoctorProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'report', 'step' => 'result'],
]];
