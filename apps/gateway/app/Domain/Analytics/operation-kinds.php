<?php

declare(strict_types=1);

use App\Domain\Analytics\AnalyticsOperationCompletion;
use App\Domain\Analytics\InstanceAnalyticsOperationCompletion;
use App\Domain\Integrations\IntegrationExecution;

return (static function (): array {
    $kinds = [];
    foreach (['enable', 'update', 'disable', 'clickhouse_converge'] as $verb) {
        $commands = $verb === 'clickhouse_converge' ? ['analytics.configure-clickhouse'] : ['firewall.rules', 'http.await-healthy', 'caddy.install-package', 'caddy.install-certificate', 'caddy.remove-certificate'];
        $runs = [['transport' => 'gateway', 'name' => 'integration.certificate-record'], ['transport' => 'gateway', 'name' => 'integration.receipt']];
        foreach ($commands as $command) {
            $runs[] = ['transport' => 'agent', 'name' => $command,
                ...($command === 'caddy.install-certificate' ? ['secrets' => ['certificate' => 'integration.payload', 'key' => 'integration.payload']] : [])];
        }
        $runs = array_map(static fn (array $run): array => [...$run, 'resumable' => true], $runs);
        $kinds['analytics.'.$verb] = ['executor' => 'swamp', 'completion' => AnalyticsOperationCompletion::class, 'execution' => IntegrationExecution::class,
            'runs' => $runs,
            'children' => [
                ...array_map(static fn (string $kind): array => ['kind' => $kind, 'node_from' => 'input.node_id'], ['process.create', 'process.update', 'process.restart', 'process.destroy', 'route.caddy_converge']),
                ['kind' => 'analytics.clickhouse_converge', 'node_from' => 'input.clickhouse_node_id'],
                ['kind' => 'route.private_dns_publish', 'node_from' => 'input.dns_node_id'],
            ],
            'completion_contract' => ['step' => 'integration-receipt', 'transport' => 'gateway', 'name' => 'integration.receipt', 'result' => ['backend' => $verb === 'disable' ? 'absent' : 'active']],
            'result_fields' => ['backend', 'changed', 'matches', 'healthy', 'recorded', 'removed', 'error_code']];
    }
    foreach (['instance_enable', 'instance_disable'] as $verb) {
        $kinds['analytics.'.$verb] = ['executor' => 'swamp', 'completion' => InstanceAnalyticsOperationCompletion::class, 'execution' => IntegrationExecution::class,
            'runs' => [['transport' => 'gateway', 'name' => 'integration.receipt', 'resumable' => true]],
            'children' => [['kind' => 'route.analytics_publish', 'node_from' => 'input.route_node_ids'], ['kind' => 'route.analytics_remove', 'node_from' => 'input.route_node_ids']],
            'completion_contract' => ['step' => 'integration-receipt', 'transport' => 'gateway', 'name' => 'integration.receipt', 'result' => ['backend' => $verb === 'instance_disable' ? 'absent' : 'active']],
            'result_fields' => ['backend', 'error_code']];
    }

    return $kinds;
})();
