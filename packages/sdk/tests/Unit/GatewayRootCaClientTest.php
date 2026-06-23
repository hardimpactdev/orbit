<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit;

use Orbit\Sdk\Laravel\GatewayRootCaClient;
use Orbit\Sdk\Laravel\Testing\GatewayMockClient;
use Orbit\Sdk\Laravel\Testing\GatewayMockResponse;
use Orbit\Sdk\Laravel\Testing\GatewayPendingRequest;
use Orbit\Sdk\Laravel\Tests\TestCase;

uses(TestCase::class);

afterEach(function (): void {
    GatewayMockClient::destroyGlobal();
});

it('fetches the gateway root CA over HTTP without following ordinary responses', function (): void {
    $requests = [];

    GatewayMockClient::global([
        '*' => function (GatewayPendingRequest $pendingRequest) use (&$requests): GatewayMockResponse {
            $requests[] = $pendingRequest->url();

            return GatewayMockResponse::make('CA CERT', 200);
        },
    ]);

    $response = (new GatewayRootCaClient(timeout: 5))->fetch('10.6.0.2');

    expect($response->successful())->toBeTrue()
        ->and($response->body())->toBe('CA CERT')
        ->and($requests)->toBe(['http://10.6.0.2/api/ca/root']);
});

it('follows same-gateway HTTPS redirects for root CA bootstrap', function (): void {
    $requests = [];

    GatewayMockClient::global([
        '*' => function (GatewayPendingRequest $pendingRequest) use (&$requests): GatewayMockResponse {
            $requests[] = $pendingRequest->url();

            if (count($requests) === 1) {
                return GatewayMockResponse::make('', 302, [
                    'Location' => 'https://10.6.0.2/api/ca/root',
                ]);
            }

            return GatewayMockResponse::make('HTTPS CA CERT', 200);
        },
    ]);

    $response = (new GatewayRootCaClient(timeout: 5))->fetch('10.6.0.2');

    expect($response->successful())->toBeTrue()
        ->and($response->body())->toBe('HTTPS CA CERT')
        ->and($requests)->toBe([
            'http://10.6.0.2/api/ca/root',
            'https://10.6.0.2/api/ca/root',
        ]);
});

it('does not follow redirects away from the requested gateway root CA endpoint', function (): void {
    $requests = [];

    GatewayMockClient::global([
        '*' => function (GatewayPendingRequest $pendingRequest) use (&$requests): GatewayMockResponse {
            $requests[] = $pendingRequest->url();

            return GatewayMockResponse::make('', 302, [
                'Location' => 'https://other.example.com/api/ca/root',
            ]);
        },
    ]);

    $response = (new GatewayRootCaClient(timeout: 5))->fetch('10.6.0.2');

    expect($response->status())->toBe(302)
        ->and($response->header('Location'))->toBe('https://other.example.com/api/ca/root')
        ->and($requests)->toBe(['http://10.6.0.2/api/ca/root']);
});
