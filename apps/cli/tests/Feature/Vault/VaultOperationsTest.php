<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Orbit\Sdk\Requests\Vault\SyncVaultRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('follows Vault sync to completion and forwards its exact retry key', function (): void {
    $directory = sys_get_temp_dir().'/orbit-cli-vault-'.Str::uuid();
    config()->set('orbit.home', $directory);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://gateway.example.test', '/fixture-ca.pem'));
    $id = '11111111-1111-4111-8111-111111111111';
    $base = ['id' => 12, 'kind' => 'vault.sync', 'target_type' => 'vault', 'target_id' => 7, 'input' => [], 'sequence' => 1, 'poll_after_seconds' => 0];
    $final = [...$base, 'status' => 'succeeded', 'result' => ['outcome' => 'succeeded', 'data' => ['backend' => 'synced']]];
    $mock = MockClient::global([
        SyncVaultRequest::class => MockResponse::make(['data' => [...$base, 'status' => 'queued', 'result' => null], 'meta' => ['request_id' => $id]], 202),
        ShowRealtimeRequest::class => MockResponse::make([], 503),
        ShowOperationRequest::class => MockResponse::make(['data' => $final, 'meta' => ['request_id' => $id]]),
    ]);

    try {
        $this->artisan('vault:sync', ['--idempotency-key' => 'vault-retry', '--json' => true])
            ->expectsOutput(json_encode(OperationResponse::fromGatewayData($final, $id)->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))->assertExitCode(0);
        $mock->assertSent(fn ($request): bool => $request instanceof SyncVaultRequest && $request->headers()->get('Idempotency-Key') === 'vault-retry');
        $mock->assertSent(fn ($request): bool => $request instanceof ShowOperationRequest);
    } finally {
        MockClient::destroyGlobal();
        new Filesystem()->deleteDirectory($directory);
    }
});
