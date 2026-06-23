<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit;

use Orbit\Sdk\Laravel\GatewayResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;

uses(TestCase::class);

it('reports status and body from gateway responses', function (): void {
    $response = new GatewayResponse(
        status: 201,
        body: '{"success":{"data":{"ok":true}}}',
    );

    expect($response->successful())->toBeTrue()
        ->and($response->status())->toBe(201)
        ->and($response->body())->toBe('{"success":{"data":{"ok":true}}}');
});

it('returns scalar headers case insensitively', function (): void {
    $response = new GatewayResponse(
        status: 302,
        body: '',
        headers: [
            'location' => ['https://10.6.0.2/api/ca/root'],
            'X-Orbit-Node' => 'gateway',
        ],
    );

    expect($response->header('Location'))->toBe('https://10.6.0.2/api/ca/root')
        ->and($response->header('X-Orbit-Node'))->toBe('gateway');
});

it('treats non 2xx statuses as unsuccessful', function (): void {
    $response = new GatewayResponse(
        status: 503,
        body: 'Service Unavailable',
    );

    expect($response->successful())->toBeFalse();
});
