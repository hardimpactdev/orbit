<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Workspaces;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Workspaces\SetupWorkspaceRequest;
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
                        'lifecycle_status' => 'active',
                    ],
                ],
                'meta' => [
                    'node' => 'app-1',
                    'http_probe' => ['reachable' => true, 'status' => '200'],
                    'warnings' => [
                        [
                            'code' => 'workspace.http_probe_unhealthy',
                            'family' => null,
                            'message' => 'Workspace did not become reachable: timed_out',
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
        ->toBe('active')
        ->and($dto->action)
        ->toBe('adopted')
        ->and($dto->httpProbe)
        ->toBe(['reachable' => true, 'status' => '200'])
        ->and($dto->warnings)
        ->toBe([
            [
                'code' => 'workspace.http_probe_unhealthy',
                'family' => null,
                'message' => 'Workspace did not become reachable: timed_out',
                'next_command' => "orbit workspace:setup 'feature-docs' --instance='docs.development'",
            ],
        ]);
});
