<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsFleetProjection;

return ['metrics.reconcile' => [
    'workflow' => 'operation-metrics-fleet',
    'projection' => MetricsFleetProjection::class,
    'private_material' => ['fleet_runtime' => 'metrics.fleet_runtime'],
]];
