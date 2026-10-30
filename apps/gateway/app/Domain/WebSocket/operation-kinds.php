<?php

declare(strict_types=1);

use App\Domain\WebSocket\WebSocketOperationCompletion;

return (static function (): array {
    $kinds = [];
    foreach (['app_create', 'app_destroy', 'app_rotate'] as $verb) {
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

    $kinds['websocket.sync'] = ['executor' => 'swamp',
        'result_fields' => ['outcome', 'data.backend', 'data.error_code', 'error_code']];

    return $kinds;
})();
