<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Tests\TestCase;

uses(TestCase::class);
it('resolves base url from local gateway settings', function (): void {
    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');

    expect($connector->resolveBaseUrl())->toBe('https://10.6.0.2');
});

it('configures verify, allow_redirects, and timeouts', function (): void {
    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $config = $connector->config()->all();

    expect($config)
        ->toHaveKey('verify', '/path/to/ca.pem')
        ->toHaveKey('allow_redirects', false)
        ->toHaveKey('timeout', 900)
        ->toHaveKey('connect_timeout', 10);
});

it('sends Accept: application/json by default', function (): void {
    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $headers = $connector->headers()->all();

    expect($headers)->toHaveKey('Accept', 'application/json');
});

it('can identify scheduler-originated gateway clients without changing transport trust', function (): void {
    $connector = GatewayConnector::forScheduler(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $headers = $connector->headers()->all();
    $config = $connector->config()->all();

    expect($headers)
        ->toHaveKey('Accept', 'application/json')
        ->toHaveKey('X-Orbit-Client', 'scheduler')
        ->and($config)
        ->toHaveKey('verify', '/path/to/ca.pem')
        ->toHaveKey('allow_redirects', false);
});
