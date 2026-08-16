<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Processes;

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\Instance;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Processes\ProcessRuntimeContextResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

it('resolves the current instance placement instead of stale process node data', function (): void {
    $oldNode = createTestAppHostNode(['name' => 'beast']);
    $newNode = createTestAppHostNode(['name' => 'nmbp']);
    $app = App::factory()->create(['name' => 'mealou']);
    $instance = Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(node_id: $oldNode->id),
    ]);
    $process = Process::factory()
        ->forOwner($app, $oldNode)
        ->create([
            'instance_id' => $instance->id,
            'name' => 'dev',
        ]);
    $instance->forceFill([
        'driver_config' => new OrbitInstanceDriverConfigData(node_id: $newNode->id),
    ])->save();
    DB::table('processes')->where('id', $process->id)->update(['node_id' => $oldNode->id]);
    $process->refresh();

    $resolved = app(ProcessRuntimeContextResolver::class)->executionNode($process);

    expect($resolved?->is($newNode))->toBeTrue()->and($process->node_id)->toBe($oldNode->id);
});

it('falls back to systemd for an unknown stored runtime', function (): void {
    $process = new Process;
    $process->setRawAttributes(['runtime' => 'future-runtime']);

    expect(app(ProcessRuntimeContextResolver::class)->runtime($process))
        ->toBe(ProcessRuntime::Systemd);
});

it('returns the main context before workspaces in stable id order', function (): void {
    $node = createTestAppHostNode(['name' => 'app-development']);
    $app = App::factory()->create(['name' => 'docs']);
    $instance = Instance::factory()->for($app)->create([
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(node_id: $node->id),
    ]);
    $first = Workspace::factory()->for($app, 'app')->create([
        'instance_id' => $instance->id,
        'name' => 'feature-a',
    ]);
    $second = Workspace::factory()->for($app, 'app')->create([
        'instance_id' => $instance->id,
        'name' => 'feature-b',
    ]);
    $process = Process::factory()
        ->forOwner($app, $node)
        ->create([
            'instance_id' => $instance->id,
            'name' => 'vite',
        ]);
    $resolver = app(ProcessRuntimeContextResolver::class);

    $resolver->loadRuntimeApp($process, withWorkspaces: true);
    $contexts = $resolver->contexts($process);

    expect($contexts)
        ->toHaveCount(3)
        ->and($contexts[0])
        ->toBeNull()
        ->and(array_map(static fn (?Workspace $workspace): ?int => $workspace?->id, $contexts))
        ->toBe([null, $first->id, $second->id]);
});

it('excludes workspace contexts on an app production node', function (): void {
    $node = createTestAppHostNode(['name' => 'app-production']);
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => NodeRoleName::AppProduction->value,
        'status' => 'active',
    ]);
    $app = App::factory()->create(['name' => 'docs']);
    $instance = Instance::factory()->for($app)->create([
        'name' => 'production',
        'driver_config' => new OrbitInstanceDriverConfigData(node_id: $node->id),
    ]);
    Workspace::factory()->for($app, 'app')->create([
        'instance_id' => $instance->id,
        'name' => 'feature-a',
    ]);
    $process = Process::factory()
        ->forOwner($app, $node)
        ->create([
            'instance_id' => $instance->id,
            'name' => 'queue',
        ]);
    $resolver = app(ProcessRuntimeContextResolver::class);

    $resolver->loadRuntimeApp($process, withWorkspaces: true);

    expect($resolver->contexts($process))->toBe([null]);
});
