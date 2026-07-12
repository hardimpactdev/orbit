<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Workspaces;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Workspaces\RemoveWorkspaceStepRequest;
use Orbit\Sdk\Laravel\Responses\Workspaces\WorkspaceStepMutationResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);
it('resolves to DELETE /api/workspaces/steps/{phase}/{step}', function (): void {
    $request = new RemoveWorkspaceStepRequest(
        phase: 'setup',
        step: 12,
        app: 'docs.development',
    );

    expect($request->resolveEndpoint())->toBe('/api/workspaces/steps/setup/12');
    expect($request->getMethod())->toBe(Method::DELETE);
    expect($request->query()->all())->toBe(['app' => 'docs.development']);
    expect($request->body()->all())->toBe([
        'destructive_consent' => true,
        'destructive_consent_source' => 'force',
    ]);
});

it('returns a WorkspaceStepMutationResponse DTO', function (): void {
    $mock = new MockClient([
        RemoveWorkspaceStepRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'result' => ['action' => 'removed'],
                    'step' => [
                        'id' => 12,
                        'app' => 'docs',
                        'app_instance' => 'development',
                        'phase' => 'setup',
                        'order' => 1,
                        'command' => 'composer install',
                        'timeout_seconds' => 600,
                    ],
                ],
                'meta' => ['remaining_step_count' => 0],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new RemoveWorkspaceStepRequest(
        phase: 'setup',
        step: 12,
        app: 'docs.development',
    ))->dto();

    expect($dto)->toBeInstanceOf(WorkspaceStepMutationResponse::class);
    expect($dto->result)->toBe(['action' => 'removed']);
    expect($dto->step)->toBe([
        'id' => 12,
        'app' => 'docs',
        'app_instance' => 'development',
        'phase' => 'setup',
        'order' => 1,
        'command' => 'composer install',
        'timeout_seconds' => 600,
    ]);
    expect($dto->meta)->toBe(['remaining_step_count' => 0]);
});
