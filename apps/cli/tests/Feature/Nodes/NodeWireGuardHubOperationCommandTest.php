<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\ConvergeNodeWireGuardHubRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('follows a hub Operation and renders its terminal result', function (bool $json, int $node): void {
    $home = sys_get_temp_dir().'/orbit-converge-'.Str::uuid();
    config(['orbit.home' => $home]);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', caPath: '/unused'));
    $operation = ['id' => 7, 'kind' => 'node.wireguard_hub_converge', 'target_type' => 'node', 'target_id' => $node,
        'status' => 'succeeded', 'sequence' => 3, 'input' => [], 'steps' => [], 'result' => [],
        'finished_at' => '2026-10-28T12:00:00Z'];
    $envelope = ['data' => $operation, 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']];
    $mock = MockClient::global([
        ConvergeNodeWireGuardHubRequest::class => MockResponse::make($envelope, 202),
        ShowOperationRequest::class => MockResponse::make($envelope),
        '*/api/v1/realtime' => MockResponse::make(['error' => ['code' => 'http.404', 'message' => 'Unavailable']], 404),
    ]);

    try {
        $this->artisan('node:wireguard-hub:converge', ['node' => (string) $node,
            '--idempotency-key' => 'hub-proof', '--json' => $json])
            ->expectsOutputToContain($json ? 'node.wireguard_hub_converge' : 'WireGuard hub')->assertExitCode(0);
        $mock->assertSent(fn ($pending): bool => $pending instanceof ConvergeNodeWireGuardHubRequest
            && $pending->headers()->get('Idempotency-Key') === 'hub-proof');
        if ($json) {
            $mock->assertSent(ShowOperationRequest::class);
        }
    } finally {
        MockClient::destroyGlobal();
        new Filesystem()->deleteDirectory($home);
    }
})->with([true, false])->with([1, 17]);
