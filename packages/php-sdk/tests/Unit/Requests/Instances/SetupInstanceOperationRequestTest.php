<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Instances\SetupInstanceRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('keeps setup bodyless and carries its key while returning the queued Operation', function (): void {
    $request = new SetupInstanceRequest(17, 'setup-proof');
    $mock = new MockClient([SetupInstanceRequest::class => MockResponse::make(['data' => [
        'id' => 42, 'kind' => 'instance.run_steps', 'target_type' => 'instance', 'target_id' => 17,
        'input' => ['source' => 'project', 'list' => 'setup'], 'status' => 'queued', 'sequence' => 1,
        'current_step' => null, 'failed_step' => null, 'error_code' => null, 'result' => null,
        'cancel_requested' => false, 'attempts' => 0, 'triggered_by' => 'operator',
        'created_at' => '2026-10-28T10:00:00+00:00', 'started_at' => null, 'finished_at' => null, 'poll_after_seconds' => 2,
    ], 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a846']], 202)]);
    $connector = new GatewayConnector('https://gateway.test');
    $connector->withMockClient($mock);
    $operation = $connector->send($request)->dto();
    expect($request->getMethod())->toBe(Method::POST)
        ->and($request->resolveEndpoint())->toBe('/api/v1/instances/17/setup')
        ->and($request)->not->toBeInstanceOf(HasBody::class)
        ->and($request->headers()->get('Idempotency-Key'))->toBe('setup-proof')
        ->and($operation)->toBeInstanceOf(OperationResponse::class)
        ->and($operation->id)->toBe(42)->and($operation->status)->toBe('queued')
        ->and($operation->requestId)->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a846');
    $mock->assertSentCount(1);
});
