<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Proof\DrainProofPoolRequest;
use Orbit\Sdk\Requests\Proof\ResumeProofPoolRequest;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('drains and resumes the warm proof copy pool and reads its state', function (string $request, string $path): void {
    $connector = new GatewayConnector('https://gateway.orbit', caPemPath: '/tmp/root.pem');
    $connector->withMockClient(new MockClient([$request => MockResponse::make([
        'data' => ['drained' => true, 'warm_copies' => [12], 'proof_copies' => [9, 10]], 'meta' => ['request_id' => 'pool-id'],
    ])]));
    $sent = new $request;

    $response = $connector->send($sent)->dto();

    expect($sent->resolveEndpoint())->toBe($path)->and($sent->getMethod())->toBe(Method::POST)
        ->and($response->toArray())->toBe(['drained' => true, 'warm_copies' => [12], 'proof_copies' => [9, 10]]);
})->with([
    'drain' => [DrainProofPoolRequest::class, '/api/v1/proof/pool/drain'],
    'resume' => [ResumeProofPoolRequest::class, '/api/v1/proof/pool/resume'],
]);

it('refuses a pool state without its copies', function (): void {
    $connector = new GatewayConnector('https://gateway.orbit', caPemPath: '/tmp/root.pem');
    $connector->withMockClient(new MockClient([DrainProofPoolRequest::class => MockResponse::make(['data' => ['drained' => true, 'warm_copies' => ['12']], 'meta' => ['request_id' => 'pool-id']])]));

    expect(fn () => $connector->send(new DrainProofPoolRequest)->dto())->toThrow(InvalidArgumentException::class);
});
