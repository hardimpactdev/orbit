<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Php;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Php\ShowPhpRuntimeRequest;
use Orbit\Sdk\Laravel\Requests\Php\UsePhpRuntimeRequest;
use Orbit\Sdk\Laravel\Responses\Php\PhpRuntimeResponse;
use Orbit\Sdk\Laravel\Responses\Php\PhpRuntimeUseResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);
it('serializes PHP runtime read filters', function (): void {
    $request = new ShowPhpRuntimeRequest(instance: 'docs', workspace: 'feature-docs', node: 'app-1', live: true);

    expect($request->resolveEndpoint())
        ->toBe('/api/php/runtime')
        ->and($request->getMethod())
        ->toBe(Method::GET)
        ->and($request->query()->all())
        ->toBe([
            'instance' => 'docs',
            'workspace' => 'feature-docs',
            'node' => 'app-1',
            'live' => true,
        ]);
});

it('serializes PHP runtime write payload', function (): void {
    $request = new UsePhpRuntimeRequest(
        version: '8.5',
        instance: 'docs',
        workspace: null,
        node: null,
        cli: false,
    );

    expect($request->resolveEndpoint())
        ->toBe('/api/php/use')
        ->and($request->getMethod())
        ->toBe(Method::POST)
        ->and($request->body()->all())
        ->toBe([
            'version' => '8.5',
            'instance' => 'docs',
            'cli' => false,
        ]);
});

it('returns typed response DTOs from gateway envelopes', function (): void {
    $mock = new MockClient([
        ShowPhpRuntimeRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'php' => ['node' => 'app-1'],
                ],
                'meta' => ['live' => true],
            ],
        ], 200),
        UsePhpRuntimeRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'php' => ['node' => 'app-1'],
                    'result' => ['target' => 'app'],
                ],
                'meta' => ['warnings' => []],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    expect($connector->send(new ShowPhpRuntimeRequest)->dto())
        ->toBeInstanceOf(PhpRuntimeResponse::class)
        ->and($connector->send(new UsePhpRuntimeRequest(version: '8.5', instance: 'docs'))->dto())
        ->toBeInstanceOf(PhpRuntimeUseResponse::class);
});
