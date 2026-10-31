<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsFleetProjection;

return ['metrics.reconcile' => [
    'workflow' => 'operation-metrics-fleet',
    'workflow_inputs' => true,
    'projection' => MetricsFleetProjection::class,
    'terminal_result' => ['job' => 'result', 'step' => 'summary'],
]];
