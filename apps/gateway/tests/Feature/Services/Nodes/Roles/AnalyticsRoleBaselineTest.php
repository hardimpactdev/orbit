<?php

declare(strict_types=1);

use App\Enums\Nodes\NodeRoleStatus;
use App\Enums\Nodes\NodeStatus;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
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

it('configures Plausible with its assigned PostgreSQL and ClickHouse WireGuard endpoints', function (): void {
    $databaseNode = Node::factory()->create([
        'name' => 'database1',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.4',
        'status' => NodeStatus::Active,
    ]);
    $databasePassword = Str::random(32);
    $unrelatedDatabasePassword = Str::random(32);

    $unrelatedApp = App::factory()->create([
        'node_id' => $databaseNode->id,
    ]);
    Process::factory()
        ->forOwner($unrelatedApp, $databaseNode)
        ->create([
            'name' => 'unrelated-postgres',
            'runtime_config' => [
                'service' => 'postgres',
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

    Process::factory()
        ->forOwner($databaseNode)
        ->create([
            'name' => 'postgres16',
            'runtime' => ProcessRuntime::DockerSwarm,
            'runtime_config' => [
                'service' => 'postgres',
                'endpoint' => [
                    'host' => '10.6.0.4',
                    'port' => 5432,
                ],
                'credentials' => [
                    'database' => 'orbit',
                    'username' => 'orbit',
                    'password' => $databasePassword,
                ],
            ],
        ]);
    Process::factory()
        ->forOwner($databaseNode)
        ->create([
            'name' => 'clickhouse24',
            'runtime' => ProcessRuntime::DockerSwarm,
            'runtime_config' => [
                'service' => 'clickhouse',
                'endpoint' => [
                    'host' => '10.6.0.4',
                    'port' => 8123,
                ],
                'credentials' => [],
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
            'clickhouse_node_id' => $databaseNode->id,
        ],
    ]);
    Process::factory()
        ->forOwner($analyticsNode)
        ->create([
            'name' => 'plausible',
            'runtime' => ProcessRuntime::DockerSwarm,
            'runtime_config' => [
                'service' => 'plausible',
                'environment' => [
                    'SECRET_KEY_BASE' => 'change-me',
                ],
            ],
        ]);

    $converger = app(NodeRoleBaselineConverger::class);
    $converger->converge($analyticsNode, $assignment);

    $plausible = Process::query()
        ->ownedBy($analyticsNode)
        ->withRuntimeService('plausible')
        ->firstOrFail();
    $environment = $plausible->runtime_config['environment'];
    $secretKeyBase = $environment['SECRET_KEY_BASE'] ?? null;

    expect($plausible->runtime_config)
        ->toMatchArray([
            'version' => '3.2.1',
            'image' => 'ghcr.io/plausible/community-edition:v3.2.1',
        ])
        ->and($environment)
        ->toMatchArray([
            'DATABASE_URL' => "postgres://orbit:{$databasePassword}@10.6.0.4:5432/plausible",
            'CLICKHOUSE_DATABASE_URL' => 'http://10.6.0.4:8123/plausible',
        ])
        ->and($secretKeyBase)
        ->toBeString()
        ->not
        ->toBe('change-me')
        ->and(strlen((string) $secretKeyBase))
        ->toBeGreaterThanOrEqual(64);

    $converger->converge($analyticsNode, $assignment);

    expect(
        Process::query()
            ->ownedBy($analyticsNode)
            ->withRuntimeService('plausible')
            ->firstOrFail()
            ->runtime_config['environment']['SECRET_KEY_BASE'],
    )
        ->toBe($secretKeyBase);
});
