<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsFleetProjection;

// Direct-only. Release remains gated on the full fleet placement decision.
return ['metrics.reconcile' => [
    'workflow' => 'operation-metrics-fleet',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'metrics_fleet_writer',
    'projection' => MetricsFleetProjection::class,
    'private_material' => ['fleet_runtime' => 'metrics.fleet_runtime'],
]];
