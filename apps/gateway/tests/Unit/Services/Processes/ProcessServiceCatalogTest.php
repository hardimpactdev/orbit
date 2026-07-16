<?php

declare(strict_types=1);

use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Services\Processes\ProcessServiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orbit\Sdk\Laravel\GatewayApiException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('resolves MySQL and Redis managed services into process runtime config', function (): void {
    $node = Node::factory()->create([
        'name' => 'database-1',
        'wireguard_address' => '10.6.0.44',
    ]);

    $mysql = app(ProcessServiceCatalog::class)->resolve(
        service: 'mysql',
        version: '8.3',
        runtime: ProcessRuntime::DockerSwarm,
        node: $node,
        processName: 'mysql8',
    );

    $redis = app(ProcessServiceCatalog::class)->resolve(
        service: 'redis',
        version: '7',
        runtime: ProcessRuntime::Docker,
        node: $node,
        processName: 'redis',
    );

    expect($mysql->command)
        ->toBe('mysqld')
        ->and($mysql->versionFamily)
        ->toBe('8')
        ->and($mysql->version)
        ->toBe('8.3')
        ->and($mysql->runtimeConfig)
        ->toMatchArray([
            'service' => 'mysql',
            'version_family' => '8',
            'version' => '8.3',
            'image' => 'mysql:8.3',
            'command_mode' => 'image_entrypoint',
            'service_name' => 'orbit-mysql8',
        ])
        ->and($mysql->runtimeConfig['endpoint']['name'])
        ->toBe('mysql8')
        ->and($mysql->runtimeConfig['endpoint']['host'])
        ->toBe('10.6.0.44')
        ->and($mysql->runtimeConfig['endpoint']['port'])
        ->toBe(3308)
        ->and($mysql->runtimeConfig['ports'][0])
        ->toBe([
            'published' => 3308,
            'target' => 3306,
            'protocol' => 'tcp',
        ])
        ->and($mysql->runtimeConfig['labels']['orbit.process'])
        ->toBe('mysql8')
        ->and($mysql->runtimeConfig['labels']['orbit.process.service'])
        ->toBe('mysql')
        ->and($mysql->runtimeConfig['labels']['orbit.process.version_family'])
        ->toBe('8')
        ->and($mysql->runtimeConfig['labels']['orbit.process.version'])
        ->toBe('8.3')
        ->and($mysql->runtimeConfig['labels']['orbit.process.spec_hash'])
        ->toBe($mysql->runtimeConfig['spec_hash'])
        ->and($mysql->runtimeConfig['volumes'][0]['name'])
        ->toBe('orbit-mysql8')
        ->and($mysql->runtimeConfig['mounts'][0]['source'])
        ->toBe('/var/lib/orbit/processes/mysql8')
        ->and($redis->command)
        ->toContain('redis-server')
        ->and($redis->runtimeConfig)
        ->toMatchArray([
            'service' => 'redis',
            'version_family' => '7',
            'version' => '7.2',
            'image' => 'redis:7.2',
        ])
        ->and($redis->runtimeConfig['endpoint']['name'])
        ->toBe('redis')
        ->and($redis->runtimeConfig['endpoint']['host'])
        ->toBe('10.6.0.44')
        ->and($redis->runtimeConfig['endpoint']['port'])
        ->toBe(6379);
});

it('keeps MySQL 8 and MySQL 9 managed services distinct', function (): void {
    $node = Node::factory()->create(['wireguard_address' => '10.6.0.44']);
    $registry = app(ProcessServiceCatalog::class);

    $mysql8 = $registry->resolve('mysql', '8', ProcessRuntime::Docker, $node, 'mysql8');
    $mysql9 = $registry->resolve('mysql', '9', ProcessRuntime::Docker, $node, 'mysql9');

    expect($mysql8->runtimeConfig['endpoint']['port'])
        ->toBe(3308)
        ->and($mysql9->runtimeConfig['endpoint']['port'])
        ->toBe(3309)
        ->and($mysql8->runtimeConfig['service_name'])
        ->toBe('orbit-mysql8')
        ->and($mysql9->runtimeConfig['service_name'])
        ->toBe('orbit-mysql9')
        ->and($mysql8->runtimeConfig['spec_hash'])
        ->not->toBe($mysql9->runtimeConfig['spec_hash']);
});

it('resolves metrics managed services for Prometheus, Grafana, and node-exporter', function (): void {
    $node = Node::factory()->create([
        'name' => 'metrics-1',
        'wireguard_address' => '10.6.0.55',
    ]);

    $registry = app(ProcessServiceCatalog::class);

    $prometheus = $registry->resolve(
        service: 'prometheus',
        version: null,
        runtime: ProcessRuntime::DockerSwarm,
        node: $node,
        processName: 'prometheus',
    );
    $grafana = $registry->resolve(
        service: 'grafana',
        version: null,
        runtime: ProcessRuntime::DockerSwarm,
        node: $node,
        processName: 'grafana',
    );
    $nodeExporter = $registry->resolve(
        service: 'node-exporter',
        version: null,
        runtime: ProcessRuntime::Systemd,
        node: $node,
        processName: 'node-exporter',
    );

    expect($prometheus->version)
        ->toBe('v3.12.0')
        ->and($prometheus->command)
        ->toContain('--storage.tsdb.retention.time=15d')
        ->and($prometheus->runtimeConfig)
        ->toMatchArray([
            'service' => 'prometheus',
            'version_family' => '3',
            'version' => 'v3.12.0',
            'image' => 'prom/prometheus:v3.12.0',
            'service_name' => 'orbit-prometheus',
        ])
        ->and($prometheus->runtimeConfig['endpoint']['host'])
        ->toBe('10.6.0.55')
        ->and($prometheus->runtimeConfig['endpoint']['port'])
        ->toBe(9090)
        ->and($prometheus->runtimeConfig['labels']['orbit.process.service'])
        ->toBe('prometheus')
        ->and($grafana->version)
        ->toBe('13.0.2')
        ->and($grafana->runtimeConfig)
        ->toMatchArray([
            'service' => 'grafana',
            'version_family' => '13',
            'version' => '13.0.2',
            'image' => 'grafana/grafana:13.0.2',
            'command_mode' => 'image_entrypoint',
            'service_name' => 'orbit-grafana',
            'environment' => [
                'GF_SECURITY_ADMIN_USER' => 'admin',
                'GF_SERVER_ROOT_URL' => 'https://metrics.orbit',
            ],
        ])
        ->and($grafana->runtimeConfig['endpoint']['host'])
        ->toBe('10.6.0.55')
        ->and($grafana->runtimeConfig['endpoint']['port'])
        ->toBe(3000)
        ->and($nodeExporter->version)
        ->toBe('1.11.1')
        ->and($nodeExporter->command)
        ->toContain('node_exporter')
        ->and($nodeExporter->runtimeConfig)
        ->toMatchArray([
            'service' => 'node-exporter',
            'version_family' => '1',
            'version' => '1.11.1',
            'endpoint' => [
                'name' => 'node-exporter',
                'kind' => 'tcp',
                'host' => '10.6.0.55',
                'port' => 9100,
            ],
        ])
        ->and($nodeExporter->runtimeConfig['labels']['orbit.process.service'])
        ->toBe('node-exporter');
});

it('resolves PostgreSQL, ClickHouse, and Plausible managed services into process runtime config', function (): void {
    $node = Node::factory()->create([
        'name' => 'analytics-1',
        'wireguard_address' => '10.6.0.50',
    ]);

    $registry = app(ProcessServiceCatalog::class);

    $postgres = $registry->resolve('postgres', '16', ProcessRuntime::DockerSwarm, $node, 'postgres16');
    $clickhouse = $registry->resolve('clickhouse', '24.12', ProcessRuntime::DockerSwarm, $node, 'clickhouse24');
    $plausible = $registry->resolve('plausible', '3.2.1', ProcessRuntime::DockerSwarm, $node, 'plausible');

    expect($registry->names())
        ->toContain('postgres', 'clickhouse', 'plausible')
        ->and($postgres->runtimeConfig)
        ->toMatchArray([
            'service' => 'postgres',
            'version_family' => '16',
            'version' => '16-alpine',
            'image' => 'postgres:16-alpine',
        ])
        ->and($postgres->runtimeConfig['endpoint']['port'])
        ->toBe(5432)
        ->and($clickhouse->runtimeConfig)
        ->toMatchArray([
            'service' => 'clickhouse',
            'version_family' => '24.12',
            'version' => '24.12-alpine',
            'image' => 'clickhouse/clickhouse-server:24.12-alpine',
        ])
        ->and($clickhouse->runtimeConfig['endpoint']['port'])
        ->toBe(8123)
        ->and($clickhouse->runtimeConfig['environment'])
        ->toMatchArray([
            'CLICKHOUSE_SKIP_USER_SETUP' => '1',
        ])
        ->and($plausible->runtimeConfig)
        ->toMatchArray([
            'service' => 'plausible',
            'version_family' => '3.2.1',
            'version' => '3.2.1',
            'image' => 'ghcr.io/plausible/community-edition:v3.2.1',
        ])
        ->and($plausible->command)
        ->toBe('sh -c "/entrypoint.sh db createdb && /entrypoint.sh db migrate && /entrypoint.sh run"')
        ->and($plausible->runtimeConfig['endpoint']['port'])
        ->toBe(8000)
        ->and($plausible->runtimeConfig['environment'])
        ->toMatchArray([
            'BASE_URL' => 'https://analytics.orbit',
        ])
        ->and($plausible->runtimeConfig['labels']['orbit.process.service'])
        ->toBe('plausible')
        ->and($plausible->runtimeConfig['labels']['orbit.process.version'])
        ->toBe('3.2.1');
});

it('requires service process endpoints to use the owner node WireGuard address', function (): void {
    $node = Node::factory()->create([
        'name' => 'database-1',
        'host' => 'database-1.example.com',
        'wireguard_address' => '10.6.0.44',
    ]);

    $descriptor = app(ProcessServiceCatalog::class)->resolve(
        service: 'redis',
        version: '7',
        runtime: ProcessRuntime::Docker,
        node: $node,
        processName: 'redis',
    );

    expect($descriptor->runtimeConfig['endpoint']['host'])
        ->toBe('10.6.0.44')
        ->and($descriptor->runtimeConfig['endpoints'][0]['host'])
        ->toBe('10.6.0.44')
        ->and($descriptor->runtimeConfig['endpoint']['host'])
        ->not->toBe('database-1.example.com')->and($descriptor->runtimeConfig['endpoint']['host'])
        ->not->toBe('database-1');
});

it('uses macos user share paths for Docker service process data mounts', function (): void {
    $node = Node::factory()->create([
        'name' => 'mac-database-1',
        'platform' => 'macos_14',
        'user' => 'nckrtl',
        'wireguard_address' => '10.6.0.44',
    ]);

    $descriptor = app(ProcessServiceCatalog::class)->resolve(
        service: 'mysql',
        version: '8',
        runtime: ProcessRuntime::Docker,
        node: $node,
        processName: 'mysql8',
    );

    expect($descriptor->runtimeConfig['mounts'][0]['source'])
        ->toBe('/Users/nckrtl/.local/share/orbit/processes/mysql8')
        ->and($descriptor->runtimeConfig['mounts'][0]['target'])
        ->toBe('/var/lib/mysql');
});

it('allows database services on macos through Docker but not Docker Swarm', function (
    string $service,
    ?string $version,
): void {
    $node = Node::factory()->create([
        'name' => "mac-{$service}-1",
        'platform' => 'macos_14',
        'user' => 'nckrtl',
        'wireguard_address' => '10.6.0.44',
    ]);
    $catalog = app(ProcessServiceCatalog::class);

    $descriptor = $catalog->resolve(
        service: $service,
        version: $version,
        runtime: ProcessRuntime::Docker,
        node: $node,
        processName: $service,
    );

    expect($descriptor->runtimeConfig['service'])
        ->toBe($service)
        ->and($descriptor->runtimeConfig['mounts'][0]['source'])
        ->toBe("/Users/nckrtl/.local/share/orbit/processes/{$service}");

    try {
        $catalog->resolve(
            service: $service,
            version: $version,
            runtime: ProcessRuntime::DockerSwarm,
            node: $node,
            processName: $service,
        );
    } catch (GatewayApiException $exception) {
        expect($exception->errorCode())
            ->toBe('validation_failed')
            ->and($exception->errorMeta())
            ->toMatchArray([
                'field' => 'runtime',
                'value' => 'docker-swarm',
                'reason' => 'process_service_runtime_unsupported',
                'allowed' => ['docker'],
            ]);

        return;
    }

    $this->fail('Expected macOS Docker Swarm service runtime to be rejected.');
})->with([
    'mysql' => ['mysql', '8'],
    'postgres' => ['postgres', '16'],
    'redis' => ['redis', '7'],
]);

it('rejects service process endpoints when the owning node has no WireGuard address', function (): void {
    $node = Node::factory()->create([
        'name' => 'database-1',
        'host' => 'database-1.example.com',
        'wireguard_address' => null,
    ]);

    app(ProcessServiceCatalog::class)->resolve(
        service: 'redis',
        version: '7',
        runtime: ProcessRuntime::Docker,
        node: $node,
        processName: 'redis',
    );
})->throws(
    GatewayApiException::class,
    "Node 'database-1' cannot host service process endpoints without a WireGuard address.",
);

it('resolves Mailpit managed service with published SMTP and private Web UI', function (): void {
    $node = Node::factory()->create([
        'name' => 'beast',
        'wireguard_address' => '10.6.0.7',
    ]);

    $mailpit = app(ProcessServiceCatalog::class)->resolve(
        service: 'mailpit',
        version: null,
        runtime: ProcessRuntime::Docker,
        node: $node,
        processName: 'mailpit',
    );

    expect(app(ProcessServiceCatalog::class)->names())
        ->toContain('mailpit')
        ->and($mailpit->command)
        ->toBe('/mailpit')
        ->and($mailpit->versionFamily)
        ->toBe('latest')
        ->and($mailpit->version)
        ->toBe('latest')
        ->and($mailpit->runtimeConfig)
        ->toMatchArray([
            'service' => 'mailpit',
            'version_family' => 'latest',
            'version' => 'latest',
            'image' => 'axllent/mailpit:latest',
            'command_mode' => 'image_entrypoint',
            'service_name' => 'orbit-mailpit',
            'credentials' => [],
        ])
        ->and($mailpit->runtimeConfig['endpoint'])
        ->toMatchArray([
            'name' => 'smtp',
            'kind' => 'tcp',
            'host' => '10.6.0.7',
            'port' => 1025,
        ])
        ->and($mailpit->runtimeConfig['endpoints'])
        ->toBe([
            [
                'name' => 'smtp',
                'kind' => 'tcp',
                'host' => '10.6.0.7',
                'port' => 1025,
            ],
        ])
        ->and($mailpit->runtimeConfig['ports'])
        ->toBe([
            [
                'published' => 1025,
                'target' => 1025,
                'protocol' => 'tcp',
            ],
        ])
        ->and($mailpit->runtimeConfig['healthcheck']['command'])
        ->toContain('8025')
        ->and($mailpit->runtimeConfig['labels']['orbit.process.service'])
        ->toBe('mailpit');
});

it('rejects unsupported managed service inputs', function (Closure $operation, string $field, string $reason): void {
    $node = Node::factory()->create();

    try {
        $operation(app(ProcessServiceCatalog::class), $node);
    } catch (GatewayApiException $exception) {
        expect($exception->errorCode())
            ->toBe('validation_failed')
            ->and($exception->errorMeta())
            ->toMatchArray([
                'field' => $field,
                'reason' => $reason,
            ]);

        return;
    }

    $this->fail('Expected GatewayApiException was not thrown.');
})->with([
    'service' => [
        fn (ProcessServiceCatalog $registry, Node $node) => $registry->resolve(
            'queue',
            '1',
            ProcessRuntime::Docker,
            $node,
            'queue',
        ),
        'service',
        'unsupported_value',
    ],
    'version required' => [
        fn (ProcessServiceCatalog $registry, Node $node) => $registry->resolve(
            'mysql',
            null,
            ProcessRuntime::Docker,
            $node,
            'mysql',
        ),
        'version',
        'required',
    ],
    'version unsupported' => [
        fn (ProcessServiceCatalog $registry, Node $node) => $registry->resolve(
            'mysql',
            '10',
            ProcessRuntime::Docker,
            $node,
            'mysql10',
        ),
        'version',
        'unsupported_value',
    ],
    'uncatalogued family version unsupported' => [
        fn (ProcessServiceCatalog $registry, Node $node) => $registry->resolve(
            'mysql',
            '8.99',
            ProcessRuntime::Docker,
            $node,
            'mysql899',
        ),
        'version',
        'unsupported_value',
    ],
    'runtime unsupported' => [
        fn (ProcessServiceCatalog $registry, Node $node) => $registry->resolve(
            'redis',
            '7',
            ProcessRuntime::Systemd,
            $node,
            'redis',
        ),
        'runtime',
        'process_service_runtime_unsupported',
    ],
    'node exporter docker unsupported' => [
        fn (ProcessServiceCatalog $registry, Node $node) => $registry->resolve(
            'node-exporter',
            null,
            ProcessRuntime::DockerSwarm,
            $node,
            'node-exporter',
        ),
        'runtime',
        'process_service_runtime_unsupported',
    ],
]);
