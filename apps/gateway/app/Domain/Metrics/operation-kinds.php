<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsCaddyRestoreExecution;
use App\Domain\Metrics\MetricsEffectAdmission;
use App\Domain\Metrics\MetricsFleetCompletion;
use App\Domain\Metrics\MetricsFleetExecution;
use App\Domain\Metrics\MetricsLeafExecution;
use App\Domain\Metrics\MetricsOperationCompletion;
use App\Domain\Metrics\MetricsPrimitiveExecution;
use App\Domain\Metrics\MetricsReconcileAdmission;
use App\Domain\Metrics\MetricsServiceExecution;
use App\Domain\Operations\OperationKinds;

return (static function (): array {
    $kinds = [];
    foreach (['node_exporter' => 'metrics.node-exporter', 'cadvisor' => 'metrics.cadvisor'] as $component => $command) {
        foreach (['converge', 'remove'] as $action) {
            $kinds['metrics.'.$component.'_'.$action] = [
                'executor' => 'swamp',
                'completion' => MetricsOperationCompletion::class,
                'runs' => [['resumable' => true,
                    'transport' => 'agent', 'name' => $command,
                    'input_fields' => ['address', 'metrics_address'],
                    'input_values' => ['action' => ['operation' => $action]],
                ]],
                'completion_contract' => [
                    'step' => str_replace('_', '-', $component).'-'.$action,
                    'transport' => 'agent', 'name' => $command,
                    'result' => ['changed' => true],
                ],
                'result_fields' => ['changed', 'error_code'],
            ];
        }
    }

    foreach (['node_exporter' => 'metrics.node-exporter', 'cadvisor' => 'metrics.cadvisor'] as $component => $command) {
        foreach (['snapshot', 'restore'] as $action) {
            $kinds['metrics.'.$component.'_'.$action] = ['executor' => 'swamp', 'completion' => MetricsOperationCompletion::class,
                'execution' => MetricsLeafExecution::class,
                'runs' => [['resumable' => true, 'transport' => 'agent', 'name' => $command]],
                'completion_contract' => ['step' => 'metrics-effect', 'transport' => 'agent', 'name' => $command, 'result' => $action === 'snapshot' ? [] : ['changed' => true]],
                'result_fields' => ['configuration', 'service_active', 'firewall_source', 'changed', 'error_code']];
        }
    }
    foreach (['service_snapshot', 'service_converge', 'service_restore'] as $verb) {
        $kinds['metrics.'.$verb] = ['executor' => 'swamp', 'completion' => MetricsOperationCompletion::class,
            'execution' => MetricsServiceExecution::class,
            'runs' => array_map(static fn (string $name): array => ['transport' => 'agent', 'name' => $name, 'resumable' => true], ['metrics.service-runtime', 'php.inspect-production-pool', 'php.converge-production-runtime']),
            'completion_contract' => ['step' => 'service-exporter', 'transport' => 'agent', 'name' => 'metrics.service-runtime', 'result' => $verb === 'service_snapshot' ? [] : ['applied' => true]],
            'result_fields' => ['config', 'unit', ['name' => 'rules', 'type' => 'list', 'items' => 'string', 'max' => 16], 'active', 'enabled', 'binary', 'fingerprint', 'applied', 'error_code']];
    }
    foreach (['runtime_converge', 'runtime_verify'] as $verb) {
        $name = $verb === 'runtime_converge' ? 'metrics.runtime' : 'metrics.credentials';
        $secrets = ['action' => $verb === 'runtime_converge' ? 'metrics.runtime' : 'metrics.password'];
        $proof = $verb === 'runtime_converge' ? ['changed' => true] : ['verified' => true];
        $kinds['metrics.'.$verb] = ['executor' => 'swamp', 'completion' => MetricsOperationCompletion::class,
            'execution' => MetricsPrimitiveExecution::class,
            'runs' => [['resumable' => true, 'transport' => 'agent', 'name' => $name, 'secrets' => $secrets]],
            'completion_contract' => ['step' => 'metrics-effect', 'transport' => 'agent', 'name' => $name, 'result' => $proof],
            'result_fields' => ['changed', 'verified', 'applied', 'error_code']];
    }
    foreach (['reconcile', 'exporter_enable', 'exporter_disable'] as $verb) {
        $kinds['metrics.'.$verb] = ['executor' => 'swamp', 'completion' => MetricsFleetCompletion::class,
            'execution' => MetricsFleetExecution::class,
            'compensations' => ['metrics.node_exporter_restore', 'metrics.cadvisor_restore', 'metrics.service_restore', 'metrics.service_caddy_restore'],
            'cancellation_boundary' => 'metrics-cancellation-boundary',
            'runs' => [['resumable' => true, 'transport' => 'gateway', 'name' => 'metrics.receipt']],
            'children' => [
                ...array_map(static fn (string $kind): array => ['kind' => $kind, 'node_from' => 'input.fleet_node_ids'],
                    ['metrics.service_caddy_restore', 'metrics.node_exporter_snapshot', 'metrics.node_exporter_converge', 'metrics.node_exporter_remove', 'metrics.node_exporter_restore', 'metrics.cadvisor_snapshot', 'metrics.cadvisor_converge', 'metrics.cadvisor_remove', 'metrics.cadvisor_restore', 'metrics.service_snapshot', 'metrics.service_converge', 'metrics.service_restore', 'route.caddy_converge']),
                ...array_map(static fn (string $kind): array => ['kind' => $kind, 'node_from' => 'input.node_id'], ['metrics.runtime_converge', 'metrics.runtime_verify']),
            ],
            'completion_contract' => ['step' => 'integration-receipt', 'transport' => 'gateway', 'name' => 'metrics.receipt', 'result' => []],
            'result_fields' => ['backend', 'error_code']];
    }
    $kinds['metrics.service_caddy_restore'] = [
        'executor' => 'swamp', 'completion' => MetricsOperationCompletion::class, 'execution' => MetricsCaddyRestoreExecution::class,
        'admission' => MetricsEffectAdmission::class,
        'runs' => [['transport' => 'gateway', 'name' => 'metrics.receipt', 'resumable' => true]],
        'children' => [['kind' => 'route.caddy_converge', 'node_from' => 'operation.node_id']],
        'completion_contract' => ['step' => 'integration-receipt', 'transport' => 'gateway', 'name' => 'metrics.receipt', 'result' => ['backend' => 'active']],
        'result_fields' => ['backend', 'error_code'],
    ];
    $kinds['metrics.reconcile']['admission'] = MetricsReconcileAdmission::class;
    foreach ($kinds as $name => $definition) {
        if (preg_match('/\Ametrics\.(?:node_exporter|cadvisor|service|runtime|certificate)_/D', $name) === 1) {
            $kinds[$name]['admission'] = MetricsEffectAdmission::class;
        }
    }
    foreach ($kinds as $name => $definition) {
        if ((isset($definition['children']) && ! OperationKinds::supports('children')) || (isset($definition['compensations']) && ! OperationKinds::supports('compensations'))) {
            unset($kinds[$name]);
        }
        if (! OperationKinds::supports('list_of') && (! in_array($name, ['metrics.credentials_reset', 'metrics.service_caddy_restore'], true) && ! preg_match('/\Ametrics\.(?:node_exporter|cadvisor)_(?:converge|remove|snapshot|restore)\z/D', $name))) {
            unset($kinds[$name]);
        }
    }

    return $kinds;
})();
