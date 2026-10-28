<?php

declare(strict_types=1);

use App\Domain\Analytics\AnalyticsOperationCompletion;
use App\Domain\Analytics\InstanceAnalyticsOperationCompletion;
use App\Domain\Integrations\IntegrationChildAdmission;
use App\Domain\Integrations\IntegrationExecution;
use App\Domain\Integrations\IntegrationRoleAdmission;
use App\Domain\Integrations\IntegrationRoleCompletion;
use App\Domain\Integrations\IntegrationRoleExecution;
use App\Domain\Operations\OperationKinds;
use App\Support\ValidatedData;

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

    foreach (['role_converge' => 'enable', 'role_remove' => 'disable'] as $roleKind => $base) {
        $definition = $kinds['analytics.'.$base];
        $definition['admission'] = IntegrationRoleAdmission::class;
        $definition['execution'] = IntegrationRoleExecution::class;
        $definition['completion'] = IntegrationRoleCompletion::class;
        $definition['runs'][] = ['transport' => 'gateway', 'name' => 'integration.prepare-role', 'resumable' => true];
        $definition['result_fields'][] = 'prepared';
        $children = [];
        foreach ($definition['children'] as $raw) {
            $child = ValidatedData::object($raw);
            $children[] = [...$child, 'node_from' => str_replace('input.', 'intent.', ValidatedData::string($child['node_from']))];
        }
        $definition['children'] = $children;
        $kinds['analytics.'.$roleKind] = $definition;
    }

    $kinds['analytics.clickhouse_converge']['admission'] = IntegrationChildAdmission::class;

    foreach ($kinds as $name => $definition) {
        if (! OperationKinds::supports('children')) {
            unset($kinds[$name]);
        }
    }

    return $kinds;
})();
