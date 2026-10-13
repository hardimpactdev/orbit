<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function drop_task_sandboxes_migration(): object
{
    return require base_path('database/migrations/2026_11_01_000100_drop_task_sandboxes.php');
}

function task_sandbox_trigger_count(): int
{
    return DB::table('sqlite_master')->where('type', 'trigger')->where('sql', 'like', '%sandbox%')->count();
}

it('leaves no old task sandbox schema after migration', function (): void {
    expect(Schema::hasTable('task_sandboxes'))->toBeFalse()
        ->and(Schema::hasColumn('instances', 'task_sandbox_id'))->toBeFalse()
        ->and(Schema::hasColumn('nodes', 'compute_sandbox_id'))->toBeFalse()
        ->and(Schema::hasColumn('task_checks', 'task_sandbox_id'))->toBeFalse()
        ->and(task_sandbox_trigger_count())->toBe(0)
        ->and(DB::table('sqlite_master')->where('name', 'instances_node_id_checkout_path_unique')->value('sql'))
        ->toBe('CREATE UNIQUE INDEX instances_node_id_checkout_path_unique ON instances (node_id, checkout_path)');
});

it('restores the schema on rollback and drops it again', function (): void {
    $migration = drop_task_sandboxes_migration();

    $migration->down();

    expect(Schema::hasTable('task_sandboxes'))->toBeTrue()
        ->and(Schema::hasColumns('task_sandboxes', ['enrollment', 'pi_token', 'model_key', 'resume_requested_at']))->toBeTrue()
        ->and(Schema::hasColumn('instances', 'task_sandbox_id'))->toBeTrue()
        ->and(Schema::hasColumn('nodes', 'compute_sandbox_id'))->toBeTrue()
        ->and(Schema::hasColumn('task_checks', 'task_sandbox_id'))->toBeTrue()
        ->and(task_sandbox_trigger_count())->toBe(3);

    $migration->up();

    expect(Schema::hasTable('task_sandboxes'))->toBeFalse()
        ->and(Schema::hasColumn('instances', 'task_sandbox_id'))->toBeFalse()
        ->and(task_sandbox_trigger_count())->toBe(0);
});

it('refuses to drop a sandbox that is not destroyed', function (): void {
    $migration = drop_task_sandboxes_migration();
    $migration->down();
    DB::table('task_sandboxes')->insert([
        'id' => '12345678-1234-4234-8234-123456789abc', 'provider' => 'incus', 'name' => 'ot-live', 'state' => 'running',
        'desired_power' => 'running', 'spec' => '{}', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Destroy the old task sandboxes');
    expect(Schema::hasTable('task_sandboxes'))->toBeTrue();

    DB::table('task_sandboxes')->update(['state' => 'destroyed']);
    $migration->up();
});

it('marks a running check of a destroyed sandbox lost and leaves other checks alone', function (): void {
    $drop = drop_task_sandboxes_migration();
    $drop->down();
    $project = Project::query()->create(['name' => 'DLF', 'slug' => 'dlf', 'repository_url' => 'https://github.com/acme/dlf.git']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Work', 'brief' => 'Work', 'status' => 'cancelled']);
    $task = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'One', 'brief' => 'One', 'status' => 'cancelled']);
    $check = static fn (string $status, ?string $sandbox): int => DB::table('task_checks')->insertGetId([
        'task_id' => $task->id, 'status' => $status, 'pid' => 1, 'process_started' => 'Thu Oct  8 19:02:09 2026',
        'head_before' => str_repeat('a', 40), 'tree_before' => str_repeat('b', 40), 'started_at' => now(), 'kind' => 'baseline',
        'task_sandbox_id' => $sandbox,
    ]);
    $stuck = $check('running', '1cc42aa7-8352-4419-9b66-b8fb2d8e466c');
    $finished = $check('passed', '1cc42aa7-8352-4419-9b66-b8fb2d8e466c');
    $shared = $check('running', null);

    (require base_path('database/migrations/2026_11_01_000000_close_checks_of_destroyed_task_sandboxes.php'))->up();
    $drop->up();

    expect(DB::table('task_checks')->where('id', $stuck)->first(['status', 'finished_at', 'output']))
        ->status->toBe('lost')
        ->finished_at->not->toBeNull()
        ->output->toContain('destroyed')
        ->and(DB::table('task_checks')->where('id', $finished)->value('status'))->toBe('passed')
        ->and(DB::table('task_checks')->where('id', $shared)->value('status'))->toBe('running');
});
