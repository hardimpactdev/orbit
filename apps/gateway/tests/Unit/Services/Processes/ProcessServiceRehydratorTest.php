<?php

declare(strict_types=1);

use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\Process;
use App\Services\Processes\ProcessServiceCatalog;
use App\Services\Processes\ProcessServiceRehydrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('rehydrates PostgreSQL from stored process intent without rotating credentials', function (): void {
    $node = Node::factory()->create([
        'name' => 'database-1',
        'wireguard_address' => '10.6.0.44',
    ]);
    $catalog = app(ProcessServiceCatalog::class);
    $created = $catalog->resolve(
        service: 'postgres',
        version: '17',
        runtime: ProcessRuntime::Docker,
        node: $node,
        processName: 'postgres-app',
        imageOverride: 'registry.example/postgres:17-alpine',
        serviceOptions: [
            'database' => 'app',
            'username' => 'app',
            'published_port' => 5433,
        ],
        binds: ['loopback', 'wireguard'],
    );
    $process = Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'postgres-app',
            'runtime' => ProcessRuntime::Docker,
            'command' => $created->command,
            'runtime_config' => $created->runtimeConfig,
            'credentials' => $created->credentials,
        ]);

    $first = app(ProcessServiceRehydrator::class)->resolve($process, $node);
    $second = app(ProcessServiceRehydrator::class)->resolve($process, $node);

    expect($first->runtimeConfig)
        ->toBe($created->runtimeConfig)
        ->and($second->runtimeConfig)
        ->toBe($first->runtimeConfig)
        ->and($first->credentials)
        ->toBe($created->credentials)
        ->and($second->credentials)
        ->toBe($created->credentials);
});

it('rehydrates ClickHouse with the stored credential hash and stable spec hash', function (): void {
    $node = Node::factory()->create([
        'name' => 'analytics-1',
        'wireguard_address' => '10.6.0.45',
    ]);
    $created = app(ProcessServiceCatalog::class)->resolve(
        service: 'clickhouse',
        version: '24.12',
        runtime: ProcessRuntime::Docker,
        node: $node,
        processName: 'clickhouse',
    );
    $process = Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'clickhouse',
            'runtime' => ProcessRuntime::Docker,
            'command' => $created->command,
            'runtime_config' => $created->runtimeConfig,
            'credentials' => $created->credentials,
        ]);

    $first = app(ProcessServiceRehydrator::class)->resolve($process, $node);
    $second = app(ProcessServiceRehydrator::class)->resolve($process, $node);

    expect($first->runtimeConfig['credential_hash'])
        ->toBe($created->runtimeConfig['credential_hash'])
        ->and($first->runtimeConfig['spec_hash'])
        ->toBe($created->runtimeConfig['spec_hash'])
        ->and($second->runtimeConfig)
        ->toBe($first->runtimeConfig)
        ->and($second->credentials)
        ->toBe($created->credentials);
});

it('preserves a managed service image port and bind intent during rehydration', function (): void {
    $node = Node::factory()->create([
        'name' => 'database-1',
        'wireguard_address' => '10.6.0.44',
    ]);
    $created = app(ProcessServiceCatalog::class)->resolve(
        service: 'valkey',
        version: '8',
        runtime: ProcessRuntime::Docker,
        node: $node,
        processName: 'valkey-feedback',
        imageOverride: 'registry.example/valkey:8.1',
        serviceOptions: ['published_port' => 6380],
        binds: ['loopback'],
    );
    $process = Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'valkey-feedback',
            'runtime' => ProcessRuntime::Docker,
            'command' => $created->command,
            'runtime_config' => $created->runtimeConfig,
        ]);

    $rehydrated = app(ProcessServiceRehydrator::class)->resolve($process, $node);

    expect($rehydrated->runtimeConfig)
        ->toBe($created->runtimeConfig)
        ->and($rehydrated->runtimeConfig['image'])
        ->toBe('registry.example/valkey:8.1')
        ->and($rehydrated->runtimeConfig['service_options'])
        ->toBe(['published_port' => 6380])
        ->and($rehydrated->runtimeConfig['binds'])
        ->toBe(['loopback']);
});

it('fails closed when a generated-secret service has no stored credentials', function (): void {
    $node = Node::factory()->create([
        'name' => 'database-1',
        'wireguard_address' => '10.6.0.44',
    ]);
    $created = app(ProcessServiceCatalog::class)->resolve(
        service: 'postgres',
        version: '17',
        runtime: ProcessRuntime::Docker,
        node: $node,
        processName: 'postgres-app',
        serviceOptions: [
            'database' => 'app',
            'username' => 'app',
            'published_port' => 5432,
        ],
    );
    $process = Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'postgres-app',
            'runtime' => ProcessRuntime::Docker,
            'command' => $created->command,
            'runtime_config' => $created->runtimeConfig,
            'credentials' => null,
        ]);

    expect(fn () => app(ProcessServiceRehydrator::class)->resolve($process, $node))
        ->toThrow(\RuntimeException::class, 'stored credentials');
});
