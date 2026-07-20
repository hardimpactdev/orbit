<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Tests\Unit\Requests\Projects;

use Orbit\Sdk\Laravel\GatewayConnector;
use Orbit\Sdk\Laravel\Requests\Projects\ListProjectsRequest;
use Orbit\Sdk\Laravel\Responses\Projects\ProjectListResponse;
use Orbit\Sdk\Laravel\Tests\TestCase;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(TestCase::class);
it('resolves to GET /api/projects', function (): void {
    $request = new ListProjectsRequest;

    expect($request->resolveEndpoint())->toBe('/api/projects');
    expect($request->getMethod())->toBe(Method::GET);
    expect(
        array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            new \ReflectionClass(ListProjectsRequest::class)->getConstructor()?->getParameters() ?? [],
        ),
    )->toBe(['environment']);
});

it('serializes the environment filter when provided', function (): void {
    $request = new ListProjectsRequest(environment: 'production');

    expect($request->query()->all())->toBe([
        'environment' => 'production',
    ]);
});

it('omits null filters from the query', function (): void {
    $request = new ListProjectsRequest(environment: 'development');

    expect($request->query()->all())->toBe(['environment' => 'development']);
});

it('returns a ProjectListResponse DTO with projects', function (): void {
    $mock = new MockClient([
        ListProjectsRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'projects' => [
                        [
                            'name' => 'docs',
                            'repository' => 'git@example.com:orbit/docs.git',
                            'instance_count' => 2,
                            'workspace_count' => 1,
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $connector = new GatewayConnector(baseUrl: 'https://10.6.0.2', caPemPath: '/path/to/ca.pem');
    $connector->withMockClient($mock);

    $dto = $connector->send(new ListProjectsRequest)->dto();

    expect($dto)->toBeInstanceOf(ProjectListResponse::class);
    expect($dto->projects)->toBe([
        [
            'name' => 'docs',
            'repository' => 'git@example.com:orbit/docs.git',
            'instance_count' => 2,
            'workspace_count' => 1,
        ],
    ]);
});
