<?php

declare(strict_types=1);

use App\Domain\Integrations\IntegrationExecution;
use Orbit\Extensions\AiRouter\Domain\AiRouterOperationCompletion;

return (static function (): array {
    $kinds = [];
    foreach (['setup', 'teardown', 'collector_converge'] as $verb) {
        $runs = [['transport' => 'gateway', 'name' => 'integration.certificate-record'], ['transport' => 'gateway', 'name' => 'ai_router.rename-cache', 'input_fields' => [], 'input_values' => []], ['transport' => 'gateway', 'name' => 'integration.receipt', 'input_fields' => [], 'input_values' => []]];
        foreach (['caddy.install-package', 'caddy.install-certificate', 'caddy.remove-certificate', 'ai-router.remove-source', 'http.await-healthy'] as $command) {
            $runs[] = ['transport' => 'agent', 'name' => $command, 'input_fields' => [], 'input_values' => [],
                ...($command === 'caddy.install-certificate' ? ['secrets' => ['certificate' => 'integration.payload', 'key' => 'integration.payload']] : [])];
        }
        $kinds['ai_router.'.$verb] = ['executor' => 'swamp', 'completion' => AiRouterOperationCompletion::class, 'execution' => IntegrationExecution::class,
            'runs' => $runs,
            'children' => [
                ...array_map(static fn (string $kind): array => ['kind' => $kind, 'node_from' => 'input.node_id'], ['process.create', 'process.update', 'process.destroy', 'route.caddy_converge', 'route.destroy']),
                ['kind' => 'route.private_dns_publish', 'node_from' => 'input.dns_node_id'],
            ],
            'completion_contract' => ['step' => 'integration-receipt', 'transport' => 'gateway', 'name' => 'integration.receipt',
                'result' => ['backend' => $verb === 'teardown' ? 'absent' : 'active']],
            'result_fields' => ['backend', 'changed', 'removed', 'healthy', 'recorded', 'error_code'],
        ];
    }

    return $kinds;
})();
