<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\RemoveNodeRequest;
use Orbit\Sdk\Requests\Nodes\ShowNodeRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('follows Node removal and preserves the offline claim and terminal failure', function (bool $succeeded): void {
    $home = sys_get_temp_dir().'/orbit-role-operation-'.Str::uuid();
    config(['orbit.home' => $home]);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', caPath: '/unused'));
    $operation = ['id' => 7, 'kind' => 'node.remove', 'target_type' => 'node', 'target_id' => 2,
        'status' => $succeeded ? 'succeeded' : 'failed', 'error_code' => $succeeded ? null : 'node.removal_rollback_failed',
        'sequence' => 3, 'input' => [], 'steps' => [], 'result' => ['removed' => $succeeded],
        'finished_at' => '2026-10-29T00:00:00Z'];
    $envelope = ['data' => $operation, 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']];
    $mock = MockClient::global([
        ShowNodeRequest::class => MockResponse::make(['data' => ['id' => 2, 'name' => 'dev', 'status' => 'active', 'roles' => []], 'meta' => $envelope['meta']]),
        RemoveNodeRequest::class => MockResponse::make($envelope, 202),
        ShowOperationRequest::class => MockResponse::make($envelope),
        '*/api/v1/realtime' => MockResponse::make(['error' => ['code' => 'http.404', 'message' => 'Unavailable']], 404),
    ]);

    try {
        $this->artisan('node:remove', ['node' => '2', '--force' => true, '--offline' => true, '--idempotency-key' => 'remove-proof', '--json' => true])
            ->expectsOutputToContain('node.remove')->assertExitCode($succeeded ? 0 : 1);
        $mock->assertSent(fn ($pending): bool => $pending instanceof RemoveNodeRequest && $pending->headers()->get('Idempotency-Key') === 'remove-proof'
            && $pending->body()->all() === ['force' => true, 'offline' => true]);
        $mock->assertSent(ShowOperationRequest::class);
    } finally {
        MockClient::destroyGlobal();
        new Filesystem()->deleteDirectory($home);
    }
})->with([true, false]);
