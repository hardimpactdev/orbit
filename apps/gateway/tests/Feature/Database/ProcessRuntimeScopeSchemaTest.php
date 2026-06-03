<?php

declare(strict_types=1);

use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('uses polymorphic process ownership instead of app or workspace columns', function (): void {
    expect(Schema::hasColumn('processes', 'owner_type'))->toBeTrue()
        ->and(Schema::hasColumn('processes', 'owner_id'))->toBeTrue()
        ->and(Schema::hasColumn('processes', 'node_id'))->toBeTrue()
        ->and(Schema::hasColumn('processes', 'app_id'))->toBeFalse()
        ->and(Schema::hasColumn('processes', 'workspace_id'))->toBeFalse();
});

it('stores node owned process runtime configuration', function (): void {
    $node = Node::factory()->create(['name' => 'app-1']);

    $process = $node->processes()->create([
        'node_id' => $node->id,
        'name' => 'mysql8',
        'command' => 'docker run mysql:8',
        'runtime' => ProcessRuntime::Docker,
        'tool' => 'mysql',
        'runtime_config' => [
            'image' => 'mysql:8',
            'ports' => ['3306:3306'],
            'volumes' => ['mysql8-data:/var/lib/mysql'],
        ],
        'sort_order' => 1,
    ]);

    expect($process->refresh())
        ->owner->toBeInstanceOf(Node::class)
        ->node_id->toBe($node->id)
        ->tool->toBe('mysql')
        ->runtime_config->toBe([
            'image' => 'mysql:8',
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
        'tool' => 'opencode',
        'runtime_config' => [
            'service' => 'opencode-server',
        ],
        'sort_order' => 1,
    ]);

    expect($process->refresh())
        ->owner->toBeInstanceOf(Node::class)
        ->node_id->toBe($node->id)
        ->runtime->toBe(ProcessRuntime::Systemd)
        ->tool->toBe('opencode')
        ->runtime_config->toBe([
            'service' => 'opencode-server',
        ]);
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
        'command' => 'docker run postgres:16',
        'runtime' => ProcessRuntime::Docker,
        'tool' => 'postgres',
        'runtime_config' => [
            'image' => 'postgres:16',
        ],
        'sort_order' => 1,
    ]);

    expect($process->refresh())
        ->owner->toBeInstanceOf(NodeRoleAssignment::class)
        ->node_id->toBe($node->id)
        ->tool->toBe('postgres')
        ->runtime_config->toBe([
            'image' => 'postgres:16',
        ]);
});

it('stores app owned process runtime configuration', function (): void {
    $node = Node::factory()->create(['name' => 'app-dev-1']);
    $app = App::factory()->create(['node_id' => $node->id, 'name' => 'abc']);

    $process = $app->processes()->create([
        'node_id' => $node->id,
        'name' => 'queue',
        'command' => 'php artisan queue:work',
        'runtime' => ProcessRuntime::Supervisor,
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
    $app = App::factory()->create(['node_id' => $node->id, 'name' => 'abc']);
    $workspace = Workspace::factory()->create(['app_id' => $app->id, 'name' => 'redesign']);

    $process = $workspace->processes()->create([
        'node_id' => $node->id,
        'name' => 'horizon-redesign',
        'runtime' => ProcessRuntime::Supervisor,
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
