<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use App\Services\Analytics\AnalyticsProcessEndpointResolver;
use App\Services\Nodes\Roles\NodeRoleBaselineConverger;
use App\Services\Nodes\Roles\RoleBaselines\RoleRuntimeConverger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(RoleRuntimeConverger::class, new class extends RoleRuntimeConverger {
        public function convergeTool(Node $node, string $toolName): void {}

        public function convergeProcess(Node $node, Process $process, string $role): void {}
    });
});

/** @mago-expect lint:halstead */
it('configures Plausible with its assigned PostgreSQL and ClickHouse WireGuard endpoints', function (): void {
    $databaseNode = Node::factory()->create([
        'name' => 'database1',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.4',
        'status' => NodeStatus::Active,
    ]);
    $databasePassword = Str::random(32);
    $clickHousePassword = Str::random(32);
    $unrelatedDatabasePassword = Str::random(32);

    $unrelatedApp = App::factory()->create();
    Process::factory()
        ->forOwner($unrelatedApp, $databaseNode)
        ->create([
            'name' => 'unrelated-postgres',
            'runtime_config' => [
                'service' => 'postgres',
                'version_family' => '16',
                'endpoint' => [
                    'host' => '10.6.0.99',
                    'port' => 5432,
                ],
                'credentials' => [
                    'database' => 'unrelated',
                    'username' => 'unrelated',
                    'password' => $unrelatedDatabasePassword,
                ],
            ],
        ]);

    $postgresProcess = Process::factory()
        ->forOwner($databaseNode)
        ->create([
            'name' => 'postgres',
            'runtime' => ProcessRuntime::Docker,
            'runtime_config' => [
                'service' => 'postgres',
                'version_family' => '16',
                'endpoint' => [
                    'host' => '10.6.0.4',
                    'port' => 5432,
                ],
            ],
            'credentials' => [
                'database' => 'plausible_db',
                'username' => 'orbit',
                'password' => $databasePassword,
                'environment' => [
                    'POSTGRES_PASSWORD' => $databasePassword,
                ],
            ],
        ]);
    Process::factory()
        ->forOwner($databaseNode)
        ->create([
            'name' => 'clickhouse24',
            'runtime' => ProcessRuntime::Docker,
            'runtime_config' => [
                'service' => 'clickhouse',
                'endpoint' => [
                    'host' => '10.6.0.4',
                    'port' => 8123,
                ],
            ],
            'credentials' => [
                'database' => 'plausible_events_db',
                'username' => 'plausible',
                'password' => $clickHousePassword,
                'environment' => [
                    'CLICKHOUSE_PASSWORD' => $clickHousePassword,
                ],
            ],
        ]);

    $analyticsNode = Node::factory()->create([
        'name' => 'services1',
        'platform' => 'ubuntu_26-04',
        'wireguard_address' => '10.6.0.14',
        'status' => NodeStatus::Active,
    ]);
    $assignment = NodeRoleAssignment::factory()->for($analyticsNode)->create([
        'role' => 'analytics',
        'status' => NodeRoleStatus::Pending,
        'settings' => [
            'postgres_node_id' => $databaseNode->id,
            'postgres_process_id' => $postgresProcess->id,
            'clickhouse_node_id' => $databaseNode->id,
        ],
    ]);
    Process::factory()
        ->forOwner($analyticsNode)
        ->create([
            'name' => 'plausible',
            'runtime' => ProcessRuntime::Docker,
            'runtime_config' => [
                'service' => 'plausible',
                'environment' => [
                    'BASE_URL' => 'https://analytics.orbit',
                ],
            ],
        ]);

    $converger = app(NodeRoleBaselineConverger::class);
    $converger->converge($analyticsNode, $assignment);

    $plausible = Process::query()
        ->ownedBy($analyticsNode)
        ->withRuntimeService('plausible')
        ->firstOrFail();
    $runtimeEnvironment = $plausible->runtime_config['environment'];
    $credentials = $plausible->credentials;
    $secretEnvironment = is_array($credentials) && is_array($credentials['environment'] ?? null)
        ? $credentials['environment']
        : [];
    $secretKeyBase = $secretEnvironment['SECRET_KEY_BASE'] ?? null;

    expect($plausible->runtime_config)
        ->toMatchArray([
            'version' => '3.2.1',
            'image' => 'ghcr.io/plausible/community-edition:v3.2.1',
        ])
        ->and($plausible->runtime)
        ->toBe(ProcessRuntime::Docker)
        ->and($plausible->runtime_config['ports'][0])
        ->toMatchArray([
            'host' => '10.6.0.14',
            'published' => 8000,
            'target' => 8000,
        ])
        ->and($runtimeEnvironment)
        ->toBe([
            'BASE_URL' => 'https://analytics.orbit',
        ])
        ->and($secretEnvironment)
        ->toMatchArray([
            'DATABASE_URL' => "postgres://orbit:{$databasePassword}@10.6.0.4:5432/plausible_db",
            'CLICKHOUSE_DATABASE_URL' => "http://plausible:{$clickHousePassword}@10.6.0.4:8123/plausible_events_db",
        ])
        ->and($secretKeyBase)
        ->toBeString()
        ->not->toBe('change-me')->and(strlen((string) $secretKeyBase))->toBeGreaterThanOrEqual(
            64,
        )->and((string) $plausible->getRawOriginal('credentials'))
        ->not->toContain($databasePassword)
        ->not->toContain($clickHousePassword)
        ->not->toContain((string) $secretKeyBase);

    Process::factory()
        ->forOwner($databaseNode)
        ->create([
            'name' => 'postgres-food',
            'runtime' => ProcessRuntime::Docker,
            'runtime_config' => [
                'service' => 'postgres',
                'endpoint' => [
                    'host' => '10.6.0.4',
                    'port' => 5433,
                ],
            ],
            'credentials' => [
                'database' => 'mealou_food_catalog',
                'username' => 'mealou_food_catalog',
                'password' => Str::random(32),
            ],
        ]);

    $converger->converge($analyticsNode, $assignment);

    $reconvergedCredentials = Process::query()
        ->ownedBy($analyticsNode)
        ->withRuntimeService('plausible')
        ->firstOrFail()
        ->credentials['environment'];

    expect($reconvergedCredentials['SECRET_KEY_BASE'])
        ->toBe($secretKeyBase)
        ->and($reconvergedCredentials['DATABASE_URL'])
        ->toBe("postgres://orbit:{$databasePassword}@10.6.0.4:5432/plausible_db");
});

it('fails clearly when persisted analytics settings select PostgreSQL 18', function (): void {
    $databaseNode = Node::factory()->create([
        'name' => 'database1',
        'wireguard_address' => '10.6.0.4',
        'status' => NodeStatus::Active,
    ]);
    $postgres = Process::factory()
        ->forOwner($databaseNode)
        ->create([
            'name' => 'postgres-food',
            'runtime' => ProcessRuntime::Docker,
            'runtime_config' => [
                'service' => 'postgres',
                'version_family' => '18',
                'endpoint' => ['host' => '10.6.0.4', 'port' => 5433],
            ],
        ]);
    $analyticsNode = Node::factory()->create([
        'name' => 'services1',
        'wireguard_address' => '10.6.0.14',
        'status' => NodeStatus::Active,
    ]);
    $assignment = NodeRoleAssignment::factory()->for($analyticsNode)->create([
        'role' => 'analytics',
        'settings' => [
            'postgres_node_id' => $databaseNode->id,
            'postgres_process_id' => $postgres->id,
            'clickhouse_node_id' => $databaseNode->id,
        ],
    ]);

    expect(fn () => app(AnalyticsProcessEndpointResolver::class)->resolve(
        assignment: $assignment,
        nodeIdSetting: 'postgres_node_id',
        service: 'postgres',
        processIdSetting: 'postgres_process_id',
    ))
        ->toThrow(RuntimeException::class, 'The analytics role requires PostgreSQL 16 for Plausible.');
});

it('fails clearly when legacy analytics settings have multiple PostgreSQL candidates', function (): void {
    $databaseNode = Node::factory()->create([
        'name' => 'database1',
        'wireguard_address' => '10.6.0.4',
        'status' => NodeStatus::Active,
    ]);

    foreach ([
        ['postgres',      5432],
        ['postgres-food', 5433],
    ] as [$name, $port]) {
        Process::factory()
            ->forOwner($databaseNode)
            ->create([
                'name' => $name,
                'runtime' => ProcessRuntime::Docker,
                'runtime_config' => [
                    'service' => 'postgres',
                    'version_family' => '16',
                    'endpoint' => ['host' => '10.6.0.4', 'port' => $port],
                ],
            ]);
    }

    $analyticsNode = Node::factory()->create([
        'name' => 'services1',
        'wireguard_address' => '10.6.0.14',
        'status' => NodeStatus::Active,
    ]);
    $assignment = NodeRoleAssignment::factory()->for($analyticsNode)->create([
        'role' => 'analytics',
        'settings' => [
            'postgres_node_id' => $databaseNode->id,
            'clickhouse_node_id' => $databaseNode->id,
        ],
    ]);

    expect(fn () => app(AnalyticsProcessEndpointResolver::class)->resolve(
        assignment: $assignment,
        nodeIdSetting: 'postgres_node_id',
        service: 'postgres',
        processIdSetting: 'postgres_process_id',
    ))
        ->toThrow(
            RuntimeException::class,
            'The analytics role PostgreSQL selection is ambiguous on node database1; store postgres_process_id.',
        );
});
