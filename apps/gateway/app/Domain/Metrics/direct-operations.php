<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsCredentialResetProjection;
use App\Domain\Metrics\MetricsFleetProjection;

return ['metrics.credentials_reset' => [
    'workflow' => 'operation-metrics-credentials-reset',
    'projection' => MetricsCredentialResetProjection::class,
    'private_material' => ['credential' => 'metrics.credentials_reset'],
], 'metrics.reconcile' => [
    'workflow' => 'operation-metrics-fleet',
    'projection' => MetricsFleetProjection::class,
    'private_material' => ['fleet_runtime' => 'metrics.fleet_runtime'],
], 'metrics.exporter_enable' => [
    'workflow' => 'operation-metrics-fleet',
    'projection' => MetricsFleetProjection::class,
    'private_material' => ['fleet_runtime' => 'metrics.fleet_runtime'],
], 'metrics.exporter_disable' => [
    'workflow' => 'operation-metrics-fleet',
    'projection' => MetricsFleetProjection::class,
    'private_material' => ['fleet_runtime' => 'metrics.fleet_runtime'],
]];
