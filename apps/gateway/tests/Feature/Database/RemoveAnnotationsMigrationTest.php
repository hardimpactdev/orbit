<?php

declare(strict_types=1);

use App\Actions\Tasks\RemoveTaskWorkspaceAction;
use App\Domain\Tasks\TaskSettling;
use App\Models\Instance;
use App\Models\Task;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->originalConnection = DB::getDefaultConnection();
    config(['database.connections.remove_annotations' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
    DB::setDefaultConnection('remove_annotations');
    $paths = array_values(array_filter(glob(database_path('migrations/*.php')) ?: [], static fn (string $path): bool => basename($path) < '2026_11_14_000000_remove_annotations.php'));
    Artisan::call('migrate', ['--database' => 'remove_annotations', '--path' => $paths, '--realpath' => true, '--force' => true]);
});

afterEach(function (): void {
    DB::setDefaultConnection($this->originalConnection);
    DB::purge('remove_annotations');
});

function removal_fixture(): void
{
    DB::table('projects')->insert(['id' => 1, 'name' => 'Orbit', 'slug' => 'orbit', 'code' => 'ORB', 'repository_url' => 'git@example.test:orbit.git', 'repository_identity' => 'example.test/orbit']);
    DB::table('nodes')->insert(['id' => 1, 'name' => 'node', 'public_ssh_host' => '10.44.0.7', 'status' => 'active', 'platform' => 'linux']);
    foreach ([1 => 'ordinary', 2 => 'workspace'] as $id => $name) {
        DB::table('instances')->insert(['id' => $id, 'project_id' => 1, 'node_id' => 1, 'name' => $name, 'checkout_path' => '/srv/'.$name, 'status' => 'active', 'annotator_port' => 9000 + $id]);
    }
    foreach ([1 => 'existing_thread', 3 => 'managed'] as $id => $mode) {
        DB::table('tasks')->insert(['id' => $id, 'project_id' => 1, 'title' => 'Group', 'brief' => 'Brief', 'status' => 'running', 'execution_mode' => $mode, 'taskable_type' => 'instance', 'taskable_id' => $id === 1 ? 1 : 2]);
        DB::table('tasks')->insert(['id' => $id + 1, 'parent_id' => $id, 'position' => 1, 'title' => 'Subtask', 'brief' => 'Brief', 'status' => 'todo', 'type' => $id === 1 ? 'annotation' : 'implementation']);
    }
    DB::table('annotations')->insert(['id' => 'note', 'instance_id' => 1, 'task_id' => 2, 'context' => '{}', 'command_id' => 'command', 'message_id' => 'message']);
    DB::table('annotation_events')->insert(['id' => 5]);
    DB::table('annotation_port_assignments')->insert(['instance_id' => 1, 'node_id' => 1, 'kind' => 'annotator_port', 'port' => 9001]);
    DB::table('task_comments')->insert(['task_group_id' => 1, 'task_id' => 2, 'type' => 'note', 'body' => 'Note', 'author' => 'user', 'posted_at' => now()]);
    DB::table('processes')->insert(['id' => 168, 'owner_type' => 'node', 'owner_id' => 1, 'name' => 'hand-made-annotator', 'runtime' => 'command', 'working_directory' => '/srv', 'runtime_config' => '{"command":"@nckrtl/annotator"}']);
}

function removal_migration(): Migration
{
    return require database_path('migrations/2026_11_14_000000_remove_annotations.php');
}

function removal_assert_untouched(): void
{
    expect(DB::table('annotations')->count())->toBe(1);
    expect(DB::table('annotation_events')->count())->toBe(1);
    expect(DB::table('tasks')->count())->toBe(4);
    expect(DB::table('annotation_port_assignments')->count())->toBe(1);
    expect(Schema::hasColumn('instances', 'annotator_port'))->toBeTrue();
    expect(Schema::hasColumn('processes', 'endpoint_withdrawn_at'))->toBeTrue();
    expect(Schema::hasColumn('tasks', 'target_thread_id'))->toBeTrue();
    expect(Schema::hasColumn('tasks', 'execution_mode'))->toBeTrue();
}

it('removes seeded annotation state while preserving managed tasks, Instances and hand-made Processes', function (): void {
    removal_fixture();

    removal_migration()->up();

    foreach (['annotations', 'annotation_events', 'annotation_port_assignments'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
    expect(DB::table('tasks')->orderBy('id')->pluck('id')->all())->toBe([3, 4]);
    expect(DB::table('instances')->count())->toBe(2);
    expect(DB::table('processes')->pluck('id')->all())->toBe([168]);
    expect(DB::table('task_comments')->count())->toBe(0);
    expect(DB::select('PRAGMA foreign_key_check'))->toBe([]);
    expect(Schema::hasColumn('instances', 'agentation_port'))->toBeFalse();
    expect(Schema::hasColumn('instances', 'annotator_port'))->toBeFalse();
    expect(Schema::hasColumn('processes', 'endpoint_withdrawn_at'))->toBeFalse();
    expect(Schema::hasColumn('tasks', 'target_thread_id'))->toBeFalse();
    expect(Schema::hasColumn('tasks', 'execution_mode'))->toBeFalse();

    removal_migration()->down();
    expect(DB::table('tasks')->where('id', 3)->value('execution_mode'))->toBe('managed');
    expect(DB::table('tasks')->where('id', 4)->value('execution_mode'))->toBeNull();
    expect(Schema::hasIndex('tasks', 'tasks_execution_mode_index'))->toBeTrue();
    expect(DB::table('annotations')->count())->toBe(0);
    expect(DB::table('annotation_events')->count())->toBe(0);
    removal_migration()->up();
    expect(DB::table('tasks')->count())->toBe(2);
});

it('refuses every retired preset before changing schema or data', function (string $preset): void {
    removal_fixture();
    DB::table('processes')->where('id', 168)->update(['runtime_config' => json_encode(['preset' => $preset], JSON_THROW_ON_ERROR)]);

    $writes = [];
    DB::connection()->beforeExecuting(function (string $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|create|alter|drop|replace)\b/i', $query)) {
            $writes[] = $query;
        }
    });

    expect(fn () => removal_migration()->up())->toThrow(RuntimeException::class, 'retired preset Processes remain: 168');

    expect($writes)->toBe([]);
    removal_assert_untouched();
    expect(DB::table('processes')->count())->toBe(1);
})->with(['annotator', 'agentation-mcp', 'antigravity-watch']);

it('refuses ambiguous task ownership before removing data', function (string $case): void {
    removal_fixture();
    match ($case) {
        'outside' => DB::table('tasks')->where('id', 4)->update(['type' => 'annotation']),
        'mixed' => DB::table('tasks')->where('id', 2)->update(['type' => 'implementation']),
        'null type' => DB::table('tasks')->where('id', 2)->update(['type' => null]),
        'unrelated' => DB::table('annotations')->update(['task_id' => 4]),
        'vm' => DB::table('task_vms')->insert(['group_id' => 1, 'host_node_id' => 1, 'provider' => 'incus', 'name' => 'task-1', 'state' => 'destroyed', 'wireguard_ip' => '10.9.0.1', 'pi_token' => 'token']),
    };

    $writes = [];
    DB::connection()->beforeExecuting(function (string $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|create|alter|drop|replace)\b/i', $query)) {
            $writes[] = $query;
        }
    });

    expect(fn () => removal_migration()->up())->toThrow(RuntimeException::class, 'mixed ownership or a Task VM');

    expect($writes)->toBe([]);
    removal_assert_untouched();
})->with(['outside', 'mixed', 'null type', 'unrelated', 'vm']);

it('rolls back earlier schema and data changes when a later schema operation fails', function (): void {
    removal_fixture();
    DB::statement('CREATE VIEW retained_mode_reader AS SELECT execution_mode FROM tasks');

    expect(fn () => removal_migration()->up())->toThrow(QueryException::class);

    removal_assert_untouched();
    expect(DB::table('task_comments')->count())->toBe(1);
});

function removal_workspace_candidates(): Collection
{
    $reflection = new ReflectionClass(TaskSettling::class);

    return $reflection->getMethod('abandonedWorkspaces')->invoke($reflection->newInstanceWithoutConstructor());
}

function removal_workspace_allowed(int $groupId, int $instanceId): bool
{
    $reflection = new ReflectionClass(TaskSettling::class);

    return $reflection->getMethod('shouldRemoveWorkspace')->invoke(
        $reflection->newInstanceWithoutConstructor(),
        Task::topLevel()->findOrFail($groupId),
        Instance::query()->findOrFail($instanceId),
    );
}

function removal_ownership_migration(): Migration
{
    return require database_path('migrations/2026_11_15_000000_add_task_workspace_owner_to_instances.php');
}

it('refuses to erase a protective annotation association before any write', function (string $status, bool $attached): void {
    removal_fixture();
    DB::table('instances')->where('id', 1)->update(['name' => 'task-3', 'branch_override' => 'task-3']);
    DB::table('tasks')->where('id', 3)->update([
        'status' => $status, 'taskable_type' => $attached ? 'instance' : null, 'taskable_id' => $attached ? 1 : null,
    ]);
    $writes = [];
    DB::connection()->beforeExecuting(function (string $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|create|alter|drop|replace)\b/i', $query)) {
            $writes[] = $query;
        }
    });

    expect(fn () => removal_migration()->up())->toThrow(RuntimeException::class, 'ambiguous task workspace ownership: 1');

    expect($writes)->toBe([]);
    removal_assert_untouched();
})->with(['cancelled', 'completed', 'running'])->with([false, true]);

it('never sweeps a user Instance after its annotation association was already lost', function (): void {
    removal_fixture();
    DB::table('instances')->where('id', 1)->update(['name' => 'task-3', 'branch_override' => 'task-3']);
    DB::table('tasks')->where('id', 3)->update(['status' => 'cancelled', 'taskable_type' => null, 'taskable_id' => null]);
    // Reproduce the irreversible association loss on an already migrated deployment.
    DB::table('annotations')->delete();
    DB::table('tasks')->whereIn('id', [2, 1])->delete();

    removal_migration()->up();
    removal_ownership_migration()->up();

    expect(removal_workspace_candidates()->pluck('id')->all())->toBe([]);
    expect(removal_workspace_allowed(3, 1))->toBeFalse();
    expect(app(RemoveTaskWorkspaceAction::class)->find(Task::topLevel()->findOrFail(3)))->toBeNull();
    expect(DB::table('instances')->where('id', 1)->exists())->toBeTrue();
    expect(DB::table('instances')->whereNotNull('task_workspace_owner_id')->count())->toBe(0);
});

it('keeps the migrated user Instance out of both cleanup gates while retaining proven managed cleanup', function (bool $attached): void {
    removal_fixture();
    DB::table('tasks')->where('id', 3)->update(['status' => 'cancelled']);

    removal_migration()->up();
    removal_ownership_migration()->up();
    DB::table('instances')->where('id', 1)->update(['name' => 'task-3', 'branch_override' => 'task-3']);
    if (! $attached) {
        DB::table('instances')->where('id', 1)->update(['name' => 'user-workspace']);
        DB::table('tasks')->where('id', 3)->update(['taskable_id' => null, 'taskable_type' => null]);
        DB::table('instances')->where('id', 2)->update(['name' => 'task-3', 'branch_override' => 'task-3', 'task_workspace_owner_id' => 3]);
    }

    expect(removal_workspace_candidates()->pluck('id')->all())->toBe([2]);
    expect(removal_workspace_allowed(3, 1))->toBeFalse();
    expect(removal_workspace_allowed(3, 2))->toBeTrue();
    expect(DB::table('instances')->count())->toBe(2);
})->with([true, false]);

it('rolls durable workspace ownership back without inventing lost associations on reapply', function (): void {
    removal_fixture();
    removal_migration()->up();
    $migration = require database_path('migrations/2026_11_15_000000_add_task_workspace_owner_to_instances.php');
    $migration->up();
    DB::table('instances')->where('id', 2)->update(['task_workspace_owner_id' => 3]);
    $migration->down();
    expect(Schema::hasColumn('instances', 'task_workspace_owner_id'))->toBeFalse();
    expect(DB::table('instances')->count())->toBe(2);
    $migration->up();
    expect(DB::table('instances')->whereNotNull('task_workspace_owner_id')->count())->toBe(0);
    DB::table('instances')->where('id', 2)->update(['task_workspace_owner_id' => 3]);
    DB::table('tasks')->where('id', 4)->delete();
    DB::table('tasks')->where('id', 3)->delete();
    expect(DB::table('instances')->where('id', 2)->value('task_workspace_owner_id'))->toBeNull();
});
