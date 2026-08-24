<?php

declare(strict_types=1);

use Orbit\Sdk\Laravel\GatewayConnector;

it('resolves the gateway connector from Orbit configuration', function (): void {
    Config::set('orbit.gateway.url', 'https://gateway.example.test');
    Config::set('orbit.gateway.ca_pem_path', __FILE__);

    $connector = app(GatewayConnector::class);

    expect($connector->resolveBaseUrl())->toBe('https://gateway.example.test')
        ->and($connector->caPemPath())->toBe(__FILE__);
});

it('uses the local gateway URL when no URL is configured', function (): void {
    Config::set('orbit.gateway.url', 'https://10.6.0.2');

    expect(app(GatewayConnector::class)->resolveBaseUrl())->toBe('https://10.6.0.2');
});

it('ignores an empty or invalid gateway CA path', function (string $caPemPath): void {
    Config::set('orbit.gateway.ca_pem_path', $caPemPath);

    expect(app(GatewayConnector::class)->caPemPath())->toBeNull();
})->with([
    'empty' => '',
    'missing' => '/tmp/orbit-ca-does-not-exist.pem',
]);
