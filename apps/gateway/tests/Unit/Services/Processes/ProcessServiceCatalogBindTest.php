<?php

declare(strict_types=1);

use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Services\Processes\ProcessServiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orbit\Sdk\Laravel\GatewayApiException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('defaults managed docker service binds to wireguard only', function (): void {
    $node = Node::factory()->create([
        'name' => 'database-1',
        'wireguard_address' => '10.6.0.44',
    ]);

    $descriptor = app(ProcessServiceCatalog::class)->resolve(
        service: 'valkey',
        version: '8',
        runtime: ProcessRuntime::Docker,
        node: $node,
        processName: 'valkey',
    );

    expect($descriptor->runtimeConfig['binds'])
        ->toBe(['wireguard'])
        ->and($descriptor->runtimeConfig['endpoint'])
        ->toMatchArray([
            'host' => '10.6.0.44',
            'port' => 6379,
        ])
        ->and($descriptor->runtimeConfig['ports'])
        ->toBe([
            [
                'host' => '10.6.0.44',
                'published' => 6379,
                'target' => 6379,
                'protocol' => 'tcp',
            ],
        ]);
});

it('publishes every target port on both selected hosts and prefers wireguard endpoint', function (): void {
    $node = Node::factory()->create([
        'name' => 'database-1',
        'wireguard_address' => '10.6.0.44',
    ]);

    $descriptor = app(ProcessServiceCatalog::class)->resolve(
        service: 'mailpit',
        version: null,
        runtime: ProcessRuntime::Docker,
        node: $node,
        processName: 'mailpit',
        binds: ['loopback', 'wireguard', 'loopback'],
    );

    expect($descriptor->runtimeConfig['binds'])
        ->toBe(['wireguard', 'loopback'])
        ->and($descriptor->runtimeConfig['endpoint']['host'])
        ->toBe('10.6.0.44')
        ->and($descriptor->runtimeConfig['endpoints'])
        ->toEqualCanonicalizing([
            [
                'name' => 'smtp',
                'kind' => 'tcp',
                'host' => '10.6.0.44',
                'port' => 1025,
            ],
            [
                'name' => 'smtp',
                'kind' => 'tcp',
                'host' => '127.0.0.1',
                'port' => 1025,
            ],
        ])
        ->and($descriptor->runtimeConfig['ports'])
        ->toEqualCanonicalizing([
            [
                'host' => '10.6.0.44',
                'published' => 1025,
                'target' => 1025,
                'protocol' => 'tcp',
            ],
            [
                'host' => '127.0.0.1',
                'published' => 1025,
                'target' => 1025,
                'protocol' => 'tcp',
            ],
        ]);
});

it('uses loopback as the primary endpoint when wireguard is not selected', function (): void {
    $node = Node::factory()->create([
        'name' => 'database-1',
        'wireguard_address' => '10.6.0.44',
    ]);

    $descriptor = app(ProcessServiceCatalog::class)->resolve(
        service: 'valkey',
        version: '8',
        runtime: ProcessRuntime::Docker,
        node: $node,
        processName: 'valkey',
        binds: ['loopback'],
    );

    expect($descriptor->runtimeConfig['binds'])
        ->toBe(['loopback'])
        ->and($descriptor->runtimeConfig['endpoint']['host'])
        ->toBe('127.0.0.1')
        ->and($descriptor->runtimeConfig['ports'][0]['host'])
        ->toBe('127.0.0.1');
});

it('rejects empty and unsupported bind selectors', function (array $binds, string $reason): void {
    $node = Node::factory()->create([
        'name' => 'database-1',
        'wireguard_address' => '10.6.0.44',
    ]);

    expect(fn () => app(ProcessServiceCatalog::class)->resolve(
        service: 'valkey',
        version: '8',
        runtime: ProcessRuntime::Docker,
        node: $node,
        processName: 'valkey',
        binds: $binds,
    ))
        ->toThrow(function (GatewayApiException $exception) use ($reason): void {
            expect($exception->errorCode())
                ->toBe('validation_failed')
                ->and($exception->errorMeta()['field'] ?? null)
                ->toBe('bind')
                ->and($exception->errorMeta()['reason'] ?? null)
                ->toBe($reason);
        });
})->with([
    'empty list' => [[], 'required'],
    'empty string' => [[''], 'required'],
    'arbitrary ip' => [['10.0.0.1'], 'unsupported_value'],
    'interface name' => [['eth0'], 'unsupported_value'],
]);

it('rejects bind selectors for docker swarm managed services', function (): void {
    $node = Node::factory()->create([
        'name' => 'database-1',
        'wireguard_address' => '10.6.0.44',
        'platform' => 'linux',
    ]);

    expect(fn () => app(ProcessServiceCatalog::class)->resolve(
        service: 'valkey',
        version: '8',
        runtime: ProcessRuntime::DockerSwarm,
        node: $node,
        processName: 'valkey',
        binds: ['loopback'],
    ))
        ->toThrow(function (GatewayApiException $exception): void {
            expect($exception->errorCode())
                ->toBe('validation_failed')
                ->and($exception->errorMeta()['field'] ?? null)
                ->toBe('bind')
                ->and($exception->errorMeta()['reason'] ?? null)
                ->toBe('process_bind_requires_docker_runtime');
        });
});
