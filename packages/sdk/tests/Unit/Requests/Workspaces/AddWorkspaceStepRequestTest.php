<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Workspaces;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Workspaces\AddWorkspaceStepRequest;
use Orbit\Sdk\Laravel\Responses\Workspaces\WorkspaceStepMutationResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);
it('resolves to POST /api/workspaces/steps/{phase}', function (): void {
    $request = new AddWorkspaceStepRequest(
        phase: 'setup',
        command: 'composer install',
        timeout: 600,
        instance: 'docs.development',
        before: 12,
    );

    expect($request->resolveEndpoint())->toBe('/api/workspaces/steps/setup');
    expect($request->getMethod())->toBe(Method::POST);
    expect($request->body()->all())->toBe([
        'instance' => 'docs.development',
        'command' => 'composer install',
        'timeout' => 600,
        'before' => 12,
    ]);
});

it('returns a WorkspaceStepMutationResponse DTO', function (): void {
    $mock = new MockClient([
        AddWorkspaceStepRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'result' => ['action' => 'added'],
                    'step' => [
                        'id' => 12,
                        'project' => 'docs',
                        'instance' => 'development',
                        'phase' => 'setup',
                        'order' => 1,
                        'command' => 'composer install',
                        'timeout_seconds' => 600,
                    ],
                ],
                'meta' => [],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new AddWorkspaceStepRequest(
        phase: 'setup',
        command: 'composer install',
        timeout: 600,
        instance: 'docs.development',
    ))->dto();

    expect($dto)->toBeInstanceOf(WorkspaceStepMutationResponse::class);
    expect($dto->result)->toBe(['action' => 'added']);
    expect($dto->step)->toBe([
        'id' => 12,
        'project' => 'docs',
        'instance' => 'development',
        'phase' => 'setup',
        'order' => 1,
        'command' => 'composer install',
        'timeout_seconds' => 600,
    ]);
});
