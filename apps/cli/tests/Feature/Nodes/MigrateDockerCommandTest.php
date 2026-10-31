<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Fleet\MigrateDockerRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('follows a Docker migration Operation and renders its final result', function (bool $json, int $node): void {
    $home = sys_get_temp_dir().'/orbit-converge-'.Str::uuid();
    config(['orbit.home' => $home]);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', caPath: '/unused'));
    $operation = ['id' => 7, 'kind' => 'fleet.docker_migrate', 'target_type' => 'node', 'target_id' => $node,
        'status' => 'succeeded', 'sequence' => 3, 'input' => [], 'steps' => [], 'result' => [],
        'finished_at' => '2026-10-28T12:00:00Z'];
    $envelope = ['data' => $operation, 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']];
    $mock = MockClient::global([
        MigrateDockerRequest::class => MockResponse::make($envelope, 202),
        ShowOperationRequest::class => MockResponse::make($envelope),
        '*/api/v1/realtime' => MockResponse::make(['error' => ['code' => 'http.404', 'message' => 'Unavailable']], 404),
    ]);

    try {
        $this->artisan('node:migrate-docker', ['node' => (string) $node, '--yes' => true,
            '--idempotency-key' => 'docker-proof', '--json' => $json])
            ->expectsOutputToContain($json ? 'fleet.docker_migrate' : 'Docker migration')->assertExitCode(0);
        $mock->assertSent(fn ($pending): bool => $pending instanceof MigrateDockerRequest
            && $pending->headers()->get('Idempotency-Key') === 'docker-proof');
        if ($json) {
            $mock->assertSent(ShowOperationRequest::class);
        }
    } finally {
        MockClient::destroyGlobal();
        new Filesystem()->deleteDirectory($home);
    }
})->with([true, false])->with([2, 17]);
