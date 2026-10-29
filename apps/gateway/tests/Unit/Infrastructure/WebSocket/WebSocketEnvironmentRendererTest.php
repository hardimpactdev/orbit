<?php

declare(strict_types=1);

use App\Domain\WebSocket\WebSocketServerCredentials;
use App\Infrastructure\WebSocket\WebSocketEnvironmentRenderer;
use Dotenv\Dotenv;

it('renders protected process credentials and no static app list', function (): void {
    $credentials = new WebSocketServerCredentials('base64:'.base64_encode('k'), 'process-id', 'registry-token');
    $env = new WebSocketEnvironmentRenderer()->render($credentials, 8790);
    $values = Dotenv::parse($env);
    expect($values)->toMatchArray([
        'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'CACHE_STORE' => 'file',
        'SESSION_DRIVER' => 'file', 'QUEUE_CONNECTION' => 'sync',
        'REVERB_REGISTRY_URL' => 'https://gateway.orbit/api/v1/websocket-registry',
        'REVERB_PROCESS_ID' => 'process-id', 'REVERB_REGISTRY_TOKEN' => 'registry-token',
        'REVERB_SERVER_PORT' => '8790',
    ])->not->toHaveKeys(['REVERB_APPS', 'REVERB_NOTIFICATION_TOKEN']);
    expect(print_r($credentials, true))->not->toContain('registry-token');
});
