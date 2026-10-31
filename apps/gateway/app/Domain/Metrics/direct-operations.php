<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsFleetProjection;

// Explicit user hold: existing polled kind keeps its default until the fleet decision.
return ['metrics.reconcile' => [
    'workflow' => 'operation-metrics-fleet',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'metrics_fleet_writer',
    'projection' => MetricsFleetProjection::class,
    'private_material' => ['fleet_runtime' => 'metrics.fleet_runtime'],
]];
