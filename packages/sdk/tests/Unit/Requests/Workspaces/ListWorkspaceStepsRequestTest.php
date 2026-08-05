<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Workspaces;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Workspaces\ListWorkspaceStepsRequest;
use Orbit\Sdk\Laravel\Responses\Workspaces\WorkspaceStepListResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);
it('resolves to GET /api/workspaces/steps/{phase}', function (): void {
    $request = new ListWorkspaceStepsRequest(phase: 'setup', instance: 'docs.development');

    expect($request->resolveEndpoint())->toBe('/api/workspaces/steps/setup');
    expect($request->getMethod())->toBe(Method::GET);
    expect($request->query()->all())->toBe(['instance' => 'docs.development']);
});

it('serializes path lookups when no app is provided', function (): void {
    $request = new ListWorkspaceStepsRequest(
        phase: 'teardown',
        path: '/srv/docs/.worktrees/feature-docs',
    );

    expect($request->resolveEndpoint())->toBe('/api/workspaces/steps/teardown');
    expect($request->query()->all())->toBe(['path' => '/srv/docs/.worktrees/feature-docs']);
});

it('returns a WorkspaceStepListResponse DTO', function (): void {
    $mock = new MockClient([
        ListWorkspaceStepsRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'steps' => [
                        [
                            'id' => 12,
                            'app' => 'docs',
                            'instance' => 'development',
                            'phase' => 'setup',
                            'order' => 1,
                            'command' => 'composer install',
                            'timeout_seconds' => 600,
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new ListWorkspaceStepsRequest(phase: 'setup', instance: 'docs.development'))->dto();

    expect($dto)->toBeInstanceOf(WorkspaceStepListResponse::class);
    expect($dto->steps)->toBe([
        [
            'id' => 12,
            'app' => 'docs',
            'instance' => 'development',
            'phase' => 'setup',
            'order' => 1,
            'command' => 'composer install',
            'timeout_seconds' => 600,
        ],
    ]);
});
