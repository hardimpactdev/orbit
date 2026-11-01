<?php

declare(strict_types=1);

use App\Domain\Observation\ScheduledObservation;

return [
    'fb550568-ab07-4eb4-b5f4-db92e5780dc0' => ['workflow' => 'observation-collection',
        'context_workflow' => 'observation-context', 'terminal_result' => ['job' => 'observe', 'step' => 'result'], 'projection' => ScheduledObservation::class],
    '09fb7a5a-6b19-4fb6-8f14-16382563f822' => ['workflow' => 'observation-horizon',
        'context_workflow' => 'observation-context', 'terminal_result' => ['job' => 'observe', 'step' => 'result'], 'projection' => ScheduledObservation::class],
];
