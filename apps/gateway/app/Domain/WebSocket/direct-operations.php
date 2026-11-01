<?php

declare(strict_types=1);

use App\Domain\WebSocket\DirectWebSocketProjection;

$registry = [
    'workflow' => 'operation-websocket-registry',
    'projection' => DirectWebSocketProjection::class,
    'workflow_inputs' => true,
    'terminal_result' => ['job' => 'result', 'step' => 'result'],
    'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^websocket-registry-[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$']],
];

return ['websocket.sync' => $registry, 'websocket.app_create' => $registry, 'websocket.app_destroy' => $registry, 'websocket.app_rotate' => $registry];
