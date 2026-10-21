<?php

declare(strict_types=1);

use App\Domain\WebSocket\WebSocketCredentials;
use App\Domain\WebSocket\WebSocketServerCredentials;
use App\Infrastructure\WebSocket\WebSocketEnvironmentRenderer;
use Dotenv\Dotenv;

it('renders every variable the Reverb app and reverb:start need', function (): void {
    $credentials = new WebSocketServerCredentials('base64:'.base64_encode('k'), [
        new WebSocketCredentials('gateway-id', 'gateway-key', 'gateway-secret'),
        new WebSocketCredentials('hauzer-id', 'hauzer-key', 'hauzer-secret'),
    ]);

    $env = new WebSocketEnvironmentRenderer()->render($credentials, 8790);

    expect($env)
        ->toContain('APP_ENV=production')
        ->toContain('APP_DEBUG=false')
        ->toContain('APP_KEY=base64:')
        ->toContain('CACHE_STORE=file')
        ->toContain('SESSION_DRIVER=file')
        ->toContain('QUEUE_CONNECTION=sync')
        ->toContain('LOG_CHANNEL=stderr')
        ->toContain("REVERB_APPS='[{\"app_id\":\"gateway-id\",\"key\":\"gateway-key\",\"secret\":\"gateway-secret\"},{\"app_id\":\"hauzer-id\",\"key\":\"hauzer-key\",\"secret\":\"hauzer-secret\"}]'")
        ->not->toContain('REVERB_APP_ID')
        ->toContain('REVERB_HOST=reverb.orbit')
        ->toContain('REVERB_PORT=443')
        ->toContain('REVERB_SCHEME=https')
        ->toContain('REVERB_SERVER_HOST=127.0.0.1')
        ->toContain('REVERB_SERVER_PORT=8790')
        ->toEndWith("\n");
});

it('renders the app list as JSON that the Reverb app reads back from its environment', function (): void {
    $credentials = new WebSocketServerCredentials('base64:'.base64_encode('k'), [
        new WebSocketCredentials('gateway-id', 'gateway-key', 'gateway-secret'),
        new WebSocketCredentials('hauzer-id', 'hauzer-key', 'hauzer-secret'),
    ]);

    $values = Dotenv::parse(new WebSocketEnvironmentRenderer()->render($credentials, 8790));

    expect(json_decode((string) $values['REVERB_APPS'], true, flags: JSON_THROW_ON_ERROR))->toBe([
        ['app_id' => 'gateway-id', 'key' => 'gateway-key', 'secret' => 'gateway-secret'],
        ['app_id' => 'hauzer-id', 'key' => 'hauzer-key', 'secret' => 'hauzer-secret'],
    ]);
});
