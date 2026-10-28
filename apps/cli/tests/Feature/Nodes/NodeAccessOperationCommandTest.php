<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\RemoveNodeAccessRequest;
use Orbit\Sdk\Requests\Nodes\ShowNodeRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('follows access revocation unless the command removes its own Gateway access', function (bool $self): void {
    $home = sys_get_temp_dir().'/orbit-access-'.Str::uuid();
    config(['orbit.home' => $home]);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', caPath: '/unused'));
    $operation = ['id' => 7, 'kind' => 'node.access_remove', 'target_type' => 'node', 'target_id' => 3,
        'status' => $self ? 'queued' : 'succeeded', 'sequence' => 3, 'input' => ['access' => ['self_lockout' => $self]],
        'steps' => [], 'result' => [], 'finished_at' => $self ? null : '2026-10-28T12:00:00Z'];
    $meta = ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'];
    $mock = MockClient::global([
        ShowNodeRequest::class => MockResponse::make(['data' => ['id' => 2, 'name' => 'operator', 'status' => 'active'], 'meta' => $meta]),
        RemoveNodeAccessRequest::class => MockResponse::make(['data' => $operation, 'meta' => $meta], 202),
        ShowOperationRequest::class => MockResponse::make(['data' => $operation, 'meta' => $meta]),
        '*/api/v1/realtime' => MockResponse::make(['error' => ['code' => 'http.404', 'message' => 'Unavailable']], 404),
    ]);
    try {
        $this->artisan('node:access:remove', ['consumer' => '2', 'serving' => '3', '--force' => true,
            '--idempotency-key' => 'access-proof', '--json' => true])
            ->expectsOutputToContain('node.access_remove')->assertExitCode(0);
        $mock->assertSent(fn ($pending): bool => $pending instanceof RemoveNodeAccessRequest
            && $pending->headers()->get('Idempotency-Key') === 'access-proof');
        if ($self) {
            $mock->assertNotSent(ShowOperationRequest::class);
        } else {
            $mock->assertSent(ShowOperationRequest::class);
        }
    } finally {
        MockClient::destroyGlobal();
        new Filesystem()->deleteDirectory($home);
    }
})->with([false, true]);
