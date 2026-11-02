<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsCredentialResetProjection;
use App\Domain\Metrics\MetricsExporterOperationProjection;
use App\Domain\Metrics\MetricsRoleProjection;

$exporters = [];
foreach (['metrics.exporter_enable' => 'operation-metrics-exporter-enable', 'metrics.exporter_disable' => 'operation-metrics-exporter-disable'] as $kind => $workflow) {
    $exporters[$kind] = [
        'workflow' => $workflow,
        'projection' => MetricsExporterOperationProjection::class,
        'workflow_inputs' => true,
        'terminal_result' => ['job' => 'exporter', 'step' => 'Prove the exporter state', 'native' => true],
    ];
}

$credentials = ['metrics.credentials_reset' => [
    'workflow' => 'operation-metrics-credentials-reset',
    'projection' => MetricsCredentialResetProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'credentials', 'step' => 'Assert the new login', 'native' => true],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^metrics-credentials-operation-[1-9][0-9]*-(probe|apply|confirm)$']],
]];

return $credentials + $exporters + array_fill_keys(['metrics.enable', 'metrics.disable'], [
    'workflow' => 'operation-metrics-role',
    'projection' => MetricsRoleProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'finish', 'step' => 'result'],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^metrics-role-operation-[1-9][0-9]*$']],
]);
