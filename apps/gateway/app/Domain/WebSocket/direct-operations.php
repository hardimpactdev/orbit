<?php

declare(strict_types=1);

use App\Domain\WebSocket\DirectWebSocketProjection;

$registry = [
    'workflow' => 'operation-websocket-registry',
    'projection' => DirectWebSocketProjection::class,
];

return ['websocket.sync' => $registry, 'websocket.app_create' => $registry, 'websocket.app_destroy' => $registry, 'websocket.app_rotate' => $registry];
