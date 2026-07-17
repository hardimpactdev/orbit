<?php

declare(strict_types=1);

use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Services\Processes\ProcessServiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orbit\Sdk\Laravel\GatewayApiException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('keeps managed service runtime selection independent of node tool platform rows', function (): void {
    $node = Node::factory()->create([
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.44',
    ]);

    $descriptor = app(ProcessServiceCatalog::class)->resolve(
        service: 'valkey',
        version: '8',
        runtime: ProcessRuntime::DockerSwarm,
        node: $node,
        processName: 'valkey',
    );

    expect($descriptor->runtimeConfig['image'])
        ->toBe('valkey/valkey:8.1')
        ->and($descriptor->runtimeConfig['endpoint']['host'])
        ->toBe('10.6.0.44')
        ->and($descriptor->runtimeConfig['labels']['orbit.process.service'])
        ->toBe('valkey');
});

it('rejects unsupported managed service runtimes through the service catalog', function (): void {
    $node = Node::factory()->create();

    app(ProcessServiceCatalog::class)->resolve(
        service: 'valkey',
        version: '8',
        runtime: ProcessRuntime::Systemd,
        node: $node,
        processName: 'valkey',
    );
})->throws(GatewayApiException::class, "Managed service 'valkey' does not support runtime 'systemd'.");
