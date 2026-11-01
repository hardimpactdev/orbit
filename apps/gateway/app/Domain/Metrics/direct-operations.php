<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsExporterOperationProjection;

return array_fill_keys(['metrics.exporter_enable', 'metrics.exporter_disable'], [
    'workflow' => 'operation-metrics-exporter',
    'projection' => MetricsExporterOperationProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'exporter', 'step' => 'result'],
]);
