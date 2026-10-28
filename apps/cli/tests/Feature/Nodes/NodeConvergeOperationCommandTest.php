<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\ConvergeNodeRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('follows an footprint Operation and prints its final receipt in JSON', function (): void {
    $home = sys_get_temp_dir().'/orbit-converge-'.Str::uuid();
    config(['orbit.home' => $home]);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', caPath: '/unused'));
    $operation = ['id' => 7, 'kind' => 'node.converge', 'target_type' => 'node', 'target_id' => 2,
        'status' => 'succeeded', 'sequence' => 3, 'input' => [], 'steps' => [], 'result' => [],
        'finished_at' => '2026-10-28T12:00:00Z'];
    $envelope = ['data' => $operation, 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']];
    $mock = MockClient::global([
        ConvergeNodeRequest::class => MockResponse::make($envelope, 202),
        ShowOperationRequest::class => MockResponse::make($envelope),
        '*/api/v1/realtime' => MockResponse::make(['error' => ['code' => 'http.404', 'message' => 'Unavailable']], 404),
    ]);

    try {
        $this->artisan('node:converge', ['node' => '2', '--force' => true,
            '--idempotency-key' => 'converge-proof', '--json' => true])
            ->expectsOutputToContain('node.converge')->assertExitCode(0);
        $mock->assertSent(fn ($pending): bool => $pending instanceof ConvergeNodeRequest
            && $pending->headers()->get('Idempotency-Key') === 'converge-proof');
        $mock->assertSent(ShowOperationRequest::class);
    } finally {
        MockClient::destroyGlobal();
        new Filesystem()->deleteDirectory($home);
    }
});
