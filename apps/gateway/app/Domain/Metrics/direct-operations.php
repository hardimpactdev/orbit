<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsCredentialResetProjection;
use App\Domain\Metrics\MetricsExporterOperationProjection;
use App\Domain\Metrics\MetricsRoleProjection;

$exporters = array_fill_keys(['metrics.exporter_enable', 'metrics.exporter_disable'], [
    'workflow' => 'operation-metrics-exporter',
    'projection' => MetricsExporterOperationProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'exporter', 'step' => 'result'],
]);

$credentials = ['metrics.credentials_reset' => [
    'workflow' => 'operation-metrics-credentials-reset',
    'projection' => MetricsCredentialResetProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'credentials', 'step' => 'result'],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^metrics-credentials-operation-[1-9][0-9]*$']],
]];

return $credentials + $exporters + array_fill_keys(['metrics.enable', 'metrics.disable'], [
    'workflow' => 'operation-metrics-role',
    'projection' => MetricsRoleProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'finish', 'step' => 'result'],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^metrics-role-operation-[1-9][0-9]*$']],
]);
