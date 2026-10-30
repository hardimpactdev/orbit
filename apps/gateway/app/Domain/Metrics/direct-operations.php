<?php

declare(strict_types=1);

use App\Domain\Metrics\DirectMetricsExporterProjection;

$exporter = [
    'workflow' => 'operation-metrics-exporter',
    'writer_config' => 'swamp.direct_writers',
    'writer_state' => 'metrics_exporter_writer',
    'projection' => DirectMetricsExporterProjection::class,
];

return ['metrics.node_exporter_converge' => $exporter];
