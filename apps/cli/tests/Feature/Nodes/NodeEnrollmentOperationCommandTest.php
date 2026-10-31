<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\AddNodeRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('follows the same enrollment Operation and preserves terminal failure and request correlation', function (bool $json, string $status, int $exit): void {
    $home = sys_get_temp_dir().'/orbit-node-enrollment-'.Str::uuid();
    config(['orbit.home' => $home]);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', caPath: '/unused'));
    $operation = ['id' => 7, 'kind' => 'node.add', 'target_type' => 'node', 'target_id' => 23,
        'status' => $status, 'sequence' => 3, 'input' => [], 'result' => ['id' => 23, 'name' => 'dev', 'enrolled' => $status === 'succeeded'],
        'error_code' => $status === 'failed' ? 'node.provision_failed' : null, 'finished_at' => '2026-10-31T18:00:00Z'];
    $envelope = ['data' => $operation, 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']];
    $mock = MockClient::global([
        AddNodeRequest::class => MockResponse::make($envelope, 202),
        ShowOperationRequest::class => MockResponse::make($envelope),
        '*/api/v1/realtime' => MockResponse::make(['error' => ['code' => 'http.404', 'message' => 'Unavailable']], 404),
    ]);

    try {
        $this->artisan('node:add', ['name' => 'dev', 'host' => '192.0.2.23', '--idempotency-key' => 'enrollment-proof', '--json' => $json])
            ->expectsOutputToContain('0198e15c-bf97-7c23-8f1f-61b8fe67a844')->assertExitCode($exit);
        $mock->assertSent(fn ($request): bool => $request instanceof AddNodeRequest
            && $request->headers()->get('Idempotency-Key') === 'enrollment-proof');
        if ($json) {
            $mock->assertSent(fn ($request): bool => $request instanceof ShowOperationRequest
                && $request->resolveEndpoint() === '/api/v1/operations/7');
        }
    } finally {
        MockClient::destroyGlobal();
        new Filesystem()->deleteDirectory($home);
    }
})->with([true, false])->with([['succeeded', 0], ['failed', 1]]);
