<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Instances\RegisterInstanceRequest;
use Orbit\Sdk\Responses\Instances\InstanceRegistrationResult;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('maps a registration recorded at once to the Instance', function (): void {
    $mock = new MockClient([RegisterInstanceRequest::class => MockResponse::make([
        'data' => ['id' => 42, 'name' => 'feature', 'checkout_path' => '/fast/apps/acme/feature', 'selected_branch' => 'feature'],
        'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a846'],
    ], 201)]);
    $connector = new GatewayConnector('https://gateway.example.test');
    $connector->withMockClient($mock);

    $result = $connector->send(new RegisterInstanceRequest('/fast/apps/acme/feature', projectId: 7))->dto();

    expect($result)->toBeInstanceOf(InstanceRegistrationResult::class)
        ->and($result->operation)->toBeNull()
        ->and($result->instance?->id)->toBe(42)
        ->and($result->instance?->selectedBranch)->toBe('feature');
});
