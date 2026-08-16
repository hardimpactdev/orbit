<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Workspaces;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Workspaces\CreateWorkspaceRequest;
use Orbit\Sdk\Laravel\Requests\Workspaces\SetupWorkspaceRequest;
use Orbit\Sdk\Laravel\Responses\Workspaces\CreateWorkspaceResponse;
use Orbit\Sdk\Laravel\Responses\Workspaces\SetupWorkspaceResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);

it('resolves to POST /api/workspaces/setup with setup inputs', function (): void {
    $request = new SetupWorkspaceRequest(
        name: 'feature-docs',
        instance: 'docs.development',
        path: '/srv/docs/.worktrees/feature-docs',
        callerCwd: '/srv/docs',
    );

    expect($request->resolveEndpoint())
        ->toBe('/api/workspaces/setup')
        ->and($request->getMethod())
        ->toBe(Method::POST)
        ->and($request->body()->all())
        ->toBe([
            'name' => 'feature-docs',
            'instance' => 'docs.development',
            'path' => '/srv/docs/.worktrees/feature-docs',
            'caller_cwd' => '/srv/docs',
        ]);
});

it('maps the complete canonical workspace setup response', function (): void {
    $mock = new MockClient([
        SetupWorkspaceRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'result' => ['action' => 'adopted'],
                    'workspace' => [
                        'name' => 'feature-docs',
                        'app' => 'docs',
                        'instance' => 'development',
                        'node' => 'app-1',
                        'path' => '/srv/docs/.worktrees/feature-docs',
                        'url' => 'https://feature-docs.docs.test',
                        'php_version' => '8.5',
                        'php_inherited' => false,
                        'adopted' => true,
                        'lifecycle_status' => 'expected',
                    ],
                ],
                'meta' => [
                    'node' => 'app-1',
                    'http_probe' => [
                        'url' => 'https://feature-docs.docs.test',
                        'result' => 'healthy',
                        'status_code' => 200,
                        'duration_ms' => 12,
                    ],
                    'warnings' => [
                        [
                            'code' => 'workspace.http_probe_unhealthy',
                            'family' => null,
                            'message' => "Setup completed, but the HTTP probe for 'https://feature-docs.docs.test' did not return a serving response within 10s.",
                            'next_command' => "orbit workspace:setup 'feature-docs' --instance='docs.development'",
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new SetupWorkspaceRequest(name: 'feature-docs'))->dto();

    expect($dto)
        ->toBeInstanceOf(SetupWorkspaceResponse::class)
        ->and($dto->name)
        ->toBe('feature-docs')
        ->and($dto->app)
        ->toBe('docs')
        ->and($dto->instance)
        ->toBe('development')
        ->and($dto->node)
        ->toBe('app-1')
        ->and($dto->path)
        ->toBe('/srv/docs/.worktrees/feature-docs')
        ->and($dto->url)
        ->toBe('https://feature-docs.docs.test')
        ->and($dto->phpVersion)
        ->toBe('8.5')
        ->and($dto->phpInherited)
        ->toBeFalse()
        ->and($dto->adopted)
        ->toBeTrue()
        ->and($dto->lifecycleStatus)
        ->toBe('expected')
        ->and($dto->action)
        ->toBe('adopted')
        ->and($dto->httpProbe)
        ->toBe([
            'url' => 'https://feature-docs.docs.test',
            'result' => 'healthy',
            'status_code' => 200,
            'duration_ms' => 12,
        ])
        ->and($dto->warnings)
        ->toBe([
            [
                'code' => 'workspace.http_probe_unhealthy',
                'family' => null,
                'message' => "Setup completed, but the HTTP probe for 'https://feature-docs.docs.test' did not return a serving response within 10s.",
                'next_command' => "orbit workspace:setup 'feature-docs' --instance='docs.development'",
            ],
        ]);
});

it('preserves setup response compatibility properties and node and url types', function (): void {
    $mock = new MockClient([
        SetupWorkspaceRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'result' => ['action' => 'converged'],
                    'workspace' => [
                        'name' => 'feature-docs',
                        'app' => 'docs',
                        'instance' => 'development',
                        'node' => 'app-1',
                        'url' => 'https://feature-docs.docs.test',
                    ],
                ],
                'meta' => [],
            ],
        ], 200),
    ]);
    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new SetupWorkspaceRequest(name: 'feature-docs'))->dto();
    $legacy = new SetupWorkspaceResponse(
        'docs',
        'development',
        'feature-docs',
        'app-1',
        'https://feature-docs.docs.test',
        'set_up',
        [],
        ['completed' => 1],
        ['started' => 1],
        ['result' => 'healthy'],
    );
    $reflection = new \ReflectionClass(SetupWorkspaceResponse::class);

    expect($dto)
        ->toBeInstanceOf(SetupWorkspaceResponse::class)
        ->and($dto->workspace)
        ->toBe('feature-docs')
        ->and($dto->setupSteps)
        ->toBe([])
        ->and($dto->processes)
        ->toBe([])
        ->and($legacy->workspace)
        ->toBe('feature-docs')
        ->and($legacy->setupSteps)
        ->toBe(['completed' => 1])
        ->and($legacy->processes)
        ->toBe(['started' => 1])
        ->and($reflection->getProperty('node')->getType()?->getName())
        ->toBe('string')
        ->and($reflection->getProperty('url')->getType()?->getName())
        ->toBe('string');
});

it('preserves null warning families in create workspace responses', function (): void {
    $mock = new MockClient([
        CreateWorkspaceRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'result' => ['action' => 'created'],
                    'workspace' => [
                        'name' => 'feature-docs',
                        'app' => 'docs',
                        'instance' => 'development',
                    ],
                ],
                'meta' => [
                    'base' => 'main',
                    'warnings' => [
                        [
                            'code' => 'workspace.http_probe_unhealthy',
                            'family' => null,
                            'message' => 'HTTP probe did not return a serving response.',
                            'next_command' => "orbit workspace:setup 'feature-docs' --instance='docs.development'",
                        ],
                    ],
                ],
            ],
        ], 201),
    ]);
    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new CreateWorkspaceRequest(
        name: 'feature-docs',
        instance: 'docs.development',
    ))->dto();

    expect($dto)
        ->toBeInstanceOf(CreateWorkspaceResponse::class)
        ->and($dto->warnings)
        ->toBe([
            [
                'code' => 'workspace.http_probe_unhealthy',
                'family' => null,
                'message' => 'HTTP probe did not return a serving response.',
                'next_command' => "orbit workspace:setup 'feature-docs' --instance='docs.development'",
            ],
        ]);
});
