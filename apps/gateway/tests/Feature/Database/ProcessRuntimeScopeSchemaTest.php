<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use App\Models\Workspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('uses polymorphic process ownership instead of app or workspace columns', function (): void {
    expect(Schema::hasColumn('processes', 'owner_type'))
        ->toBeTrue()
        ->and(Schema::hasColumn('processes', 'owner_id'))
        ->toBeTrue()
        ->and(Schema::hasColumn('processes', 'node_id'))
        ->toBeTrue()
        ->and(Schema::hasColumn('processes', 'app_id'))
        ->toBeFalse()
        ->and(Schema::hasColumn('processes', 'workspace_id'))
        ->toBeFalse();
});

it('stores concrete app instance ownership for process definitions and events', function (): void {
    expect(Schema::hasColumn('processes', 'instance_id'))
        ->toBeTrue()
        ->and(Schema::hasColumn('process_events', 'instance_id'))
        ->toBeTrue();
});

it('derives an app process node from its concrete instance instead of legacy app placement', function (): void {
    $instanceNode = Node::factory()->create(['name' => 'production-app-node']);
    $app = App::factory()->create(['name' => 'docs']);
    $instance = Instance::factory()->for($app)->create([
        'name' => 'production',
        'driver_config' => new OrbitInstanceDriverConfigData(node_id: $instanceNode->id),
    ]);

    $process = $app->processes()->create([
        'instance_id' => $instance->id,
        'name' => 'queue',
        'command' => 'php artisan queue:work',
        'sort_order' => 1,
    ]);

    expect($process->node_id)->toBe($instanceNode->id);
});

it('rejects an app process node that contradicts its concrete instance placement', function (): void {
    $legacyNode = Node::factory()->create(['name' => 'legacy-app-node']);
    $instanceNode = Node::factory()->create(['name' => 'production-app-node']);
    $app = App::factory()->create(['name' => 'docs']);
    $instance = Instance::factory()->for($app)->create([
        'name' => 'production',
        'driver_config' => new OrbitInstanceDriverConfigData(node_id: $instanceNode->id),
    ]);

    expect(fn (): Process => $app->processes()->create([
        'instance_id' => $instance->id,
        'node_id' => $legacyNode->id,
        'name' => 'queue',
        'command' => 'php artisan queue:work',
        'sort_order' => 1,
    ]))
        ->toThrow(InvalidArgumentException::class, 'canonical owner placement');
});

it('rejects app process writes without concrete app instance ownership', function (): void {
    $node = Node::factory()->create(['name' => 'app-dev-1']);
    $app = App::factory()->create(['name' => 'docs']);

    expect(fn (): Process => $app->processes()->create([
        'node_id' => $node->id,
        'name' => 'queue',
        'command' => 'php artisan queue:work',
        'sort_order' => 1,
    ]))
        ->toThrow(InvalidArgumentException::class, 'requires concrete instance ownership');
});

it('rejects workspace process writes without concrete app instance ownership', function (): void {
    $node = Node::factory()->create(['name' => 'app-dev-1']);
    $app = App::factory()->create(['name' => 'docs']);
    $workspace = Workspace::factory()->create(['app_id' => $app->id, 'name' => 'redesign']);

    expect(fn (): Process => $workspace
        ->processes()
        ->create([
            'node_id' => $node->id,
            'name' => 'horizon',
            'command' => 'php artisan horizon',
            'sort_order' => 1,
        ]))
        ->toThrow(InvalidArgumentException::class, 'requires concrete instance ownership');
});

it('stores node owned process runtime configuration', function (): void {
    $node = Node::factory()->create(['name' => 'app-1']);

    $process = $node->processes()->create([
        'node_id' => $node->id,
        'name' => 'mysql8',
        'command' => 'mysqld',
        'runtime' => ProcessRuntime::Docker,
        'tool' => null,
        'runtime_config' => [
            'service' => 'mysql',
            'version' => '8.4',
            'image' => 'mysql:8.4',
            'ports' => ['3306:3306'],
            'volumes' => ['mysql8-data:/var/lib/mysql'],
        ],
        'sort_order' => 1,
    ]);

    expect($process->refresh())
        ->owner->toBeInstanceOf(Node::class)
        ->node_id->toBe($node->id)
        ->tool->toBeNull()
        ->runtime_config->toBe([
            'service' => 'mysql',
            'version' => '8.4',
            'image' => 'mysql:8.4',
            'ports' => ['3306:3306'],
            'volumes' => ['mysql8-data:/var/lib/mysql'],
        ]);
});

it('stores node owned systemd process runtime configuration with a tool dependency', function (): void {
    $node = Node::factory()->create(['name' => 'app-dev-1']);

    $process = $node->processes()->create([
        'node_id' => $node->id,
        'name' => 'opencode-server',
        'command' => 'opencode serve -a',
        'runtime' => ProcessRuntime::Systemd,
        'tool' => 'opencode-cli',
        'runtime_config' => [
            'service' => 'opencode-server',
        ],
        'sort_order' => 1,
    ]);

    expect($process->refresh())
        ->owner->toBeInstanceOf(Node::class)
        ->node_id->toBe($node->id)
        ->runtime->toBe(ProcessRuntime::Systemd)
        ->tool->toBe('opencode-cli')
        ->runtime_config->toBe([
            'service' => 'opencode-server',
        ]);
});

it('migrates legacy process definition runtime config to service metadata', function (): void {
    $node = Node::factory()->create(['name' => 'metrics-1']);
    $definitionOnly = Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'prometheus',
            'runtime_config' => [
                'definition' => 'prometheus',
                'version' => '3',
                'labels' => [
                    'orbit.process.definition' => 'prometheus',
                    'orbit.process.version' => '3.0.0',
                ],
            ],
        ]);
    $bothSet = Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'grafana',
            'runtime_config' => [
                'service' => 'grafana',
                'definition' => 'grafana-legacy',
                'version' => '12',
                'labels' => [
                    'orbit.process.service' => 'grafana',
                    'orbit.process.definition' => 'grafana-legacy',
                    'orbit.process.version' => '12.0.0',
                ],
            ],
        ]);

    processRuntimeScopeDefinitionMigration()->up();

    expect(processRuntimeScopeConfig($definitionOnly))
        ->toBe([
            'version' => '3',
            'labels' => [
                'orbit.process.version' => '3.0.0',
                'orbit.process.service' => 'prometheus',
            ],
            'service' => 'prometheus',
        ])
        ->and(processRuntimeScopeConfig($bothSet))
        ->toBe([
            'service' => 'grafana',
            'version' => '12',
            'labels' => [
                'orbit.process.service' => 'grafana',
                'orbit.process.version' => '12.0.0',
            ],
        ]);
});

it('matches runtime services by service metadata only', function (): void {
    $node = Node::factory()->create(['name' => 'database-1']);
    Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'legacy-mysql',
            'runtime_config' => [
                'definition' => 'mysql',
            ],
        ]);
    Process::factory()
        ->forOwner($node)
        ->create([
            'name' => 'current-mysql',
            'runtime_config' => [
                'service' => 'mysql',
            ],
        ]);

    expect(
        Process::query()
            ->withRuntimeService('mysql')
            ->orderBy('name')
            ->pluck('name')
            ->all(),
    )
        ->toBe(['current-mysql']);
});

it('stores role owned process runtime configuration', function (): void {
    $node = Node::factory()->create(['name' => 'database-1']);
    $role = NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'database',
    ]);

    $process = $role->processes()->create([
        'node_id' => $node->id,
        'name' => 'postgres16',
        'command' => 'postgres',
        'runtime' => ProcessRuntime::Docker,
        'tool' => null,
        'runtime_config' => [
            'service' => 'postgres',
            'version' => '16',
            'image' => 'postgres:16',
        ],
        'sort_order' => 1,
    ]);

    expect($process->refresh())
        ->owner->toBeInstanceOf(NodeRoleAssignment::class)
        ->node_id->toBe($node->id)
        ->tool->toBeNull()
        ->runtime_config->toBe([
            'service' => 'postgres',
            'version' => '16',
            'image' => 'postgres:16',
        ]);
});

it('stores app owned process runtime configuration', function (): void {
    $node = Node::factory()->create(['name' => 'app-dev-1']);
    $app = App::factory()->create(['name' => 'abc']);
    $instance = Instance::factory()->for($app)->create([
        'driver_config' => new OrbitInstanceDriverConfigData(node_id: $node->id),
    ]);

    $process = $app->processes()->create([
        'instance_id' => $instance->id,
        'node_id' => $node->id,
        'name' => 'queue',
        'command' => 'php artisan queue:work',
        'runtime' => ProcessRuntime::Systemd,
        'tool' => 'php-cli',
        'runtime_config' => [
            'directory' => '/home/orbit/apps/abc',
        ],
        'sort_order' => 1,
    ]);

    expect($process->refresh())
        ->owner->toBeInstanceOf(App::class)
        ->node_id->toBe($node->id)
        ->tool->toBe('php-cli')
        ->runtime_config->toBe([
            'directory' => '/home/orbit/apps/abc',
        ]);
});

it('stores workspace owned process runtime configuration', function (): void {
    $node = Node::factory()->create(['name' => 'app-dev-1']);
    $app = App::factory()->placedOn($node)->create(['name' => 'abc']);
    $workspace = Workspace::factory()->create([
        'app_id' => $app->id,
        'instance_id' => $app->instances()->firstOrFail()->id,
        'name' => 'redesign',
    ]);

    $process = $workspace
        ->processes()
        ->create([
            'instance_id' => $workspace->instance_id,
            'node_id' => $node->id,
            'name' => 'horizon-redesign',
            'runtime' => ProcessRuntime::Systemd,
            'tool' => 'php-cli',
            'command' => 'php artisan horizon',
            'runtime_config' => [
                'directory' => '/home/orbit/apps/abc/worktrees/redesign',
            ],
            'sort_order' => 1,
        ]);

    expect($process->refresh())
        ->owner->toBeInstanceOf(Workspace::class)
        ->node_id->toBe($node->id)
        ->tool->toBe('php-cli')
        ->runtime_config->toBe([
            'directory' => '/home/orbit/apps/abc/worktrees/redesign',
        ]);
});

it('defaults app and workspace host command processes to systemd when runtime is omitted', function (): void {
    $node = Node::factory()->create(['name' => 'app-dev-1']);
    $app = App::factory()->placedOn($node)->create(['name' => 'abc']);
    $workspace = Workspace::factory()->create([
        'app_id' => $app->id,
        'instance_id' => $app->instances()->firstOrFail()->id,
        'name' => 'redesign',
    ]);

    $relationProcess = $app->processes()->create([
        'instance_id' => $workspace->instance_id,
        'node_id' => $node->id,
        'name' => 'queue',
        'command' => 'php artisan queue:work',
        'sort_order' => 1,
    ]);

    $factoryProcess = Process::factory()
        ->forOwner($workspace)
        ->create([
            'name' => 'horizon-redesign',
        ]);

    DB::table('processes')->insert([
        'node_id' => $node->id,
        'owner_type' => $workspace->getMorphClass(),
        'owner_id' => $workspace->id,
        'name' => 'vite-redesign',
        'command' => 'npm run dev',
        'sort_order' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($relationProcess->refresh()->runtime)
        ->toBe(ProcessRuntime::Systemd)
        ->and($factoryProcess->refresh()->runtime)
        ->toBe(ProcessRuntime::Systemd)
        ->and(DB::table('processes')->where('name', 'vite-redesign')->value('runtime'))
        ->toBe(ProcessRuntime::Systemd->value);
});

function processRuntimeScopeDefinitionMigration(): Migration
{
    $migration = require
        database_path(
            'migrations/2026_07_08_110624_migrate_process_definition_runtime_config_to_service.php',
        );

    if (! $migration instanceof Migration) {
        throw new RuntimeException('Expected process definition migration instance.');
    }

    return $migration;
}

/**
 * @return array<string, mixed>
 */
function processRuntimeScopeConfig(Process $process): array
{
    $runtimeConfig = $process->refresh()->runtime_config;

    if (! is_array($runtimeConfig)) {
        throw new RuntimeException('Expected process runtime config to be an array.');
    }

    return $runtimeConfig;
}
