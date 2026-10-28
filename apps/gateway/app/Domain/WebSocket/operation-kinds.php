<?php

declare(strict_types=1);

use App\Domain\WebSocket\WebSocketOperationCompletion;

return (static function (): array {
    $kinds = [];
    foreach (['app_create', 'app_destroy', 'app_rotate', 'sync'] as $verb) {
        $kinds['websocket.'.$verb] = [
            'executor' => 'swamp', 'completion' => WebSocketOperationCompletion::class,
            'runs' => [['resumable' => true,
                'transport' => 'remote_program', 'name' => 'websocket.registry-applied',
                'input_fields' => ['registry_operation_id'], 'input_values' => [],
                'input_schema' => ['type' => 'object', 'additionalProperties' => false,
                    'required' => ['registry_operation_id'],
                    'properties' => ['registry_operation_id' => ['type' => 'string', 'pattern' => '^[a-f0-9-]{36}$']]],
            ]],
            'completion_contract' => ['step' => 'reverb-registry-applied', 'transport' => 'remote_program',
                'name' => 'websocket.registry-applied', 'result' => ['outcome' => 'succeeded', 'data' => ['backend' => 'applied']]],
            'result_fields' => ['outcome', 'data.backend', 'data.error_code', 'error_code'],
        ];
    }

    foreach (['role_converge', 'role_remove'] as $verb) {
        $runs = [
            ['transport' => 'gateway', 'name' => 'integration.prepare-role', 'resumable' => true],
            ['transport' => 'gateway', 'name' => 'websocket.publication-state', 'resumable' => true],
            ['transport' => 'gateway', 'name' => 'integration.certificate-record', 'resumable' => true],
            ['transport' => 'gateway', 'name' => 'integration.receipt', 'resumable' => true],
        ];
        foreach (['caddy.check-addresses', 'caddy.install-package', 'role.install-prerequisites', 'websocket.converge-runtime', 'websocket.remove-runtime', 'websocket.runtime-active', 'caddy.install-certificate', 'caddy.remove-certificate'] as $command) {
            $runs[] = ['transport' => 'agent', 'name' => $command, 'resumable' => true,
                ...($command === 'websocket.converge-runtime' ? ['secrets' => ['environment' => 'integration.payload']] : []),
                ...($command === 'caddy.install-certificate' ? ['secrets' => ['certificate' => 'integration.payload', 'key' => 'integration.payload']] : [])];
        }
        $kinds['websocket.'.$verb] = ['executor' => 'swamp', 'admission' => \App\Domain\Integrations\IntegrationRoleAdmission::class,
            'execution' => \App\Domain\Integrations\IntegrationRoleExecution::class, 'completion' => \App\Domain\Integrations\IntegrationRoleCompletion::class,
            'runs' => $runs,
            'children' => [['kind' => 'route.caddy_converge', 'node_from' => 'operation.node_id'], ['kind' => 'route.private_dns_publish', 'node_from' => 'intent.dns_node_id']],
            'completion_contract' => ['step' => 'integration-receipt', 'transport' => 'gateway', 'name' => 'integration.receipt', 'result' => ['backend' => $verb === 'role_remove' ? 'absent' : 'active']],
            'result_fields' => ['prepared', 'backend', 'changed', 'removed', 'recorded', 'applied', 'verified', 'active', 'error_code']];
    }
    if (! \App\Domain\Operations\OperationKinds::supports('children')) {
        foreach ($kinds as $name => $definition) { if (isset($definition['children'])) { unset($kinds[$name]); } }
    }
    return $kinds;
})();
