<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Projects;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Projects\ShowProjectRequest;
use Orbit\Sdk\Laravel\Responses\Projects\ProjectShowResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);
it('resolves to GET /api/projects/{project}', function (): void {
    $request = new ShowProjectRequest('docs project');

    expect($request->resolveEndpoint())->toBe('/api/projects/docs%20project');
    expect($request->getMethod())->toBe(Method::GET);
});

it('returns a ProjectShowResponse DTO with project and details', function (): void {
    $mock = new MockClient([
        ShowProjectRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'project' => ['name' => 'docs'],
                    'details' => ['workspaces' => []],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new ShowProjectRequest('docs'))->dto();

    expect($dto)
        ->toBeInstanceOf(ProjectShowResponse::class)
        ->and($dto->project)
        ->toBe(['name' => 'docs'])
        ->and($dto->details)
        ->toBe(['workspaces' => []]);
});
