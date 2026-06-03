<?php

declare(strict_types=1);

use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\Process;
use App\Services\Tools\ManagedServiceToolProcessBackfill;
use App\Services\Tools\ToolPayloadMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('runs the process backfill from the data migration', function (): void {
    $node = Node::factory()->create(['name' => 'service-1']);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'redis',
        'expected_state' => 'running',
    ]);

    $migration = require database_path('migrations/2026_06_03_232935_backfill_managed_tool_services_as_processes.php');
    $migration->up();

    expect(Process::query()
        ->where('node_id', $node->id)
        ->where('name', 'redis')
        ->where('tool', 'redis')
        ->where('runtime', ProcessRuntime::Docker)
        ->exists())->toBeTrue();
});

it('backfills managed service tool rows as node owned processes while preserving tool rows', function (): void {
    $node = Node::factory()->create(['name' => 'service-1']);

    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'mysql',
        'instance_key' => 'mysql:8',
        'version_family' => '8',
        'runtime' => 'docker',
        'runtime_config' => ['image' => 'mysql:8'],
        'expected_state' => 'running',
    ]);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'postgres',
        'instance_key' => 'postgres:16',
        'version_family' => '16',
        'runtime' => 'docker',
        'runtime_config' => ['image' => 'postgres:16'],
        'expected_state' => 'running',
    ]);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'redis',
        'instance_key' => 'redis:default',
        'runtime' => 'docker',
        'runtime_config' => ['image' => 'redis:7.2'],
        'expected_state' => 'running',
    ]);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'opencode-server',
        'expected_state' => 'installed',
    ]);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'polyscope-server',
        'expected_state' => 'installed',
    ]);

    app(ManagedServiceToolProcessBackfill::class)->run();

    $processes = Process::query()
        ->where('node_id', $node->id)
        ->orderBy('name')
        ->get()
        ->keyBy('name');

    expect($processes->keys()->all())->toBe([
        'mysql8',
        'opencode-server',
        'polyscope-server',
        'postgres16',
        'redis',
    ])
        ->and(NodeTool::query()->where('node_id', $node->id)->count())->toBe(5)
        ->and($processes['mysql8']->owner_type)->toBe($node->getMorphClass())
        ->and($processes['mysql8'])->tool->toBe('mysql')
        ->and($processes['mysql8'])->runtime->toBe(ProcessRuntime::Docker)
        ->and($processes['mysql8'])->command->toBe('mysqld')
        ->and($processes['mysql8'])->runtime_config->toMatchArray(['image' => 'mysql:8'])
        ->and($processes['postgres16'])->tool->toBe('postgres')
        ->and($processes['postgres16'])->runtime->toBe(ProcessRuntime::Docker)
        ->and($processes['postgres16'])->command->toBe('postgres')
        ->and($processes['postgres16'])->runtime_config->toMatchArray(['image' => 'postgres:16'])
        ->and($processes['redis'])->tool->toBe('redis')
        ->and($processes['redis'])->runtime->toBe(ProcessRuntime::Docker)
        ->and($processes['redis'])->command->toBe('redis-server --bind 0.0.0.0 --protected-mode no')
        ->and($processes['redis'])->runtime_config->toMatchArray(['image' => 'redis:7.2'])
        ->and($processes['opencode-server'])->tool->toBe('opencode')
        ->and($processes['opencode-server'])->runtime->toBe(ProcessRuntime::Systemd)
        ->and($processes['opencode-server'])->command->toBe('opencode serve -a')
        ->and($processes['opencode-server'])->runtime_config->toMatchArray(['service' => 'opencode-server'])
        ->and($processes['polyscope-server'])->tool->toBe('polyscope')
        ->and($processes['polyscope-server'])->runtime->toBe(ProcessRuntime::Systemd)
        ->and($processes['polyscope-server'])->command->toBe('polyscope-server')
        ->and($processes['polyscope-server'])->runtime_config->toMatchArray(['service' => 'polyscope-server']);
});

it('is idempotent and does not overwrite existing managed service process rows', function (): void {
    $node = Node::factory()->create(['name' => 'service-1']);
    NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'redis',
        'expected_state' => 'running',
    ]);
    Process::factory()->forOwner($node)->create([
        'name' => 'redis',
        'tool' => 'redis',
        'runtime' => ProcessRuntime::Docker,
        'command' => 'custom redis entrypoint',
        'sort_order' => 7,
    ]);

    app(ManagedServiceToolProcessBackfill::class)->run();
    app(ManagedServiceToolProcessBackfill::class)->run();

    expect(Process::query()
        ->where('node_id', $node->id)
        ->where('tool', 'redis')
        ->count())->toBe(1);

    expect(Process::query()
        ->where('node_id', $node->id)
        ->where('name', 'redis')
        ->firstOrFail())
        ->command->toBe('custom redis entrypoint')
        ->sort_order->toBe(7);
});

it('keeps tool payloads renderable after process backfill', function (): void {
    $node = Node::factory()->create(['name' => 'service-1']);
    $tool = NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'redis',
        'expected_state' => 'running',
        'expected_version' => '7.2',
        'config' => [
            'endpoints' => [
                ['name' => 'redis', 'kind' => 'tcp', 'host' => '10.6.0.12', 'port' => 6379],
            ],
        ],
    ]);

    app(ManagedServiceToolProcessBackfill::class)->run();

    $payload = app(ToolPayloadMapper::class)->toArray($tool->fresh()->load('node'));

    expect($payload)->toMatchArray([
        'name' => 'redis',
        'node' => 'service-1',
        'expected_state' => 'running',
        'version' => '7.2',
        'managed' => true,
        'endpoints' => [
            ['name' => 'redis', 'kind' => 'tcp', 'host' => '10.6.0.12', 'port' => 6379],
        ],
    ])
        ->and(Process::query()->where('node_id', $node->id)->where('tool', 'redis')->exists())->toBeTrue();
});
