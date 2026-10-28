<?php

declare(strict_types=1);

use App\Domain\Integrations\IntegrationExecution;
use App\Domain\Metrics\MetricsFleetCompletion;
use App\Domain\Metrics\MetricsFleetExecution;
use App\Domain\Metrics\MetricsLeafExecution;
use App\Domain\Metrics\MetricsOperationCompletion;
use App\Domain\Metrics\MetricsPrimitiveExecution;
use App\Domain\Metrics\MetricsResetCompletion;
use App\Domain\Metrics\MetricsResetExecution;
use App\Domain\Metrics\MetricsServiceExecution;

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
    foreach (['runtime_converge', 'runtime_remove', 'runtime_verify', 'certificate_publish', 'certificate_remove', 'publication_firewall'] as $verb) {
        $name = match ($verb) {
            'runtime_converge', 'runtime_remove' => 'metrics.runtime', 'runtime_verify' => 'metrics.credentials', 'certificate_publish', 'certificate_remove' => 'metrics.certificate', 'publication_firewall' => 'metrics.publication-firewall'
        };
        $secrets = match ($verb) {
            'runtime_converge' => ['action' => 'metrics.runtime'], 'runtime_verify' => ['action' => 'metrics.password'], 'certificate_publish' => ['certificate' => 'metrics.certificate', 'key' => 'metrics.certificate'], default => []
        };
        $proof = match ($verb) {
            'certificate_publish', 'certificate_remove' => ['applied' => true], 'runtime_verify' => ['verified' => true], default => ['changed' => true]
        };
        $kinds['metrics.'.$verb] = ['executor' => 'swamp', 'completion' => MetricsOperationCompletion::class,
            'execution' => MetricsPrimitiveExecution::class,
            'runs' => [['resumable' => true, 'transport' => 'agent', 'name' => $name, 'secrets' => $secrets]],
            'completion_contract' => ['step' => 'metrics-effect', 'transport' => 'agent', 'name' => $name, 'result' => $proof],
            'result_fields' => ['changed', 'verified', 'applied', 'error_code']];
    }
    foreach (['enable', 'disable', 'reconcile', 'exporter_enable', 'exporter_disable'] as $verb) {
        $kinds['metrics.'.$verb] = ['executor' => 'swamp', 'completion' => MetricsFleetCompletion::class,
            'execution' => MetricsFleetExecution::class,
            'runs' => [['resumable' => true, 'transport' => 'gateway', 'name' => 'integration.receipt']],
            'children' => [
                ...array_map(static fn (string $kind): array => ['kind' => $kind, 'node_from' => 'input.fleet_node_ids'],
                    ['metrics.node_exporter_snapshot', 'metrics.node_exporter_converge', 'metrics.node_exporter_remove', 'metrics.node_exporter_restore', 'metrics.cadvisor_snapshot', 'metrics.cadvisor_converge', 'metrics.cadvisor_remove', 'metrics.cadvisor_restore', 'metrics.service_snapshot', 'metrics.service_converge', 'metrics.service_restore', 'route.caddy_converge']),
                ...array_map(static fn (string $kind): array => ['kind' => $kind, 'node_from' => 'input.node_id'], ['metrics.runtime_converge', 'metrics.runtime_remove', 'metrics.runtime_verify', 'metrics.publication_firewall']),
                ...array_map(static fn (string $kind): array => ['kind' => $kind, 'node_from' => 'input.gateway_node_id'], ['metrics.certificate_publish', 'metrics.certificate_remove', 'route.caddy_converge']),
                ['kind' => 'route.private_dns_publish', 'node_from' => 'input.dns_node_id'],
            ],
            'completion_contract' => ['step' => 'integration-receipt', 'transport' => 'gateway', 'name' => 'integration.receipt', 'result' => ['backend' => $verb === 'disable' ? 'absent' : 'active']],
            'result_fields' => ['backend', 'error_code']];
    }
    $kinds['metrics.credentials_reset'] = ['executor' => 'swamp', 'completion' => MetricsResetCompletion::class,
        'execution' => MetricsResetExecution::class,
        'runs' => [['resumable' => true, 'transport' => 'agent', 'name' => 'metrics.credentials', 'secrets' => ['action' => 'metrics.reset']]],
        'completion_contract' => ['step' => 'credential-verify', 'transport' => 'agent', 'name' => 'metrics.credentials', 'result' => ['verified' => true]],
        'result_fields' => ['changed', 'verified', 'error_code']];

    $kinds['metrics.retire'] = ['executor' => 'swamp', 'completion' => MetricsOperationCompletion::class,
        'execution' => IntegrationExecution::class,
        'runs' => [['resumable' => true, 'transport' => 'gateway', 'name' => 'integration.receipt']],
        'children' => array_map(static fn (string $kind): array => ['kind' => $kind, 'node_from' => 'operation.node_id'],
            ['metrics.node_exporter_remove', 'metrics.cadvisor_remove', 'metrics.service_converge']),
        'completion_contract' => ['step' => 'integration-receipt', 'transport' => 'gateway', 'name' => 'integration.receipt', 'result' => ['backend' => 'absent']],
        'result_fields' => ['backend', 'error_code']];

    foreach ($kinds as $name => $definition) {
        if (preg_match('/\Ametrics\.(?:node_exporter|cadvisor|service|runtime|certificate)_/D', $name) === 1 || $name === 'metrics.publication_firewall') {
            $kinds[$name]['admission'] = \App\Domain\Integrations\IntegrationChildAdmission::class;
        }
    }
    foreach (['role_converge' => 'enable', 'role_remove' => 'disable'] as $roleKind => $base) {
        $definition = $kinds['metrics.'.$base];
        $definition['admission'] = \App\Domain\Integrations\IntegrationRoleAdmission::class;
        $definition['execution'] = \App\Domain\Integrations\IntegrationRoleExecution::class;
        $definition['completion'] = \App\Domain\Integrations\IntegrationRoleCompletion::class;
        $definition['runs'][] = ['transport' => 'gateway', 'name' => 'integration.prepare-role', 'resumable' => true];
        $definition['result_fields'][] = 'prepared';
        $definition['children'] = array_map(static fn (array $child): array => [...$child, 'node_from' => str_replace('input.', 'intent.', $child['node_from'])], $definition['children']);
        $kinds['metrics.'.$roleKind] = $definition;
    }

    return $kinds;
})();
