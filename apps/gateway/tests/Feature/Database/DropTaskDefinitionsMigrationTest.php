<?php

declare(strict_types=1);

use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('leaves no task definitions table after migration', function (): void {
    expect(Schema::hasTable('task_definitions'))->toBeFalse();
});

it('drops stored definitions without changing Projects or their runtime definitions', function (): void {
    $project = Project::query()->create([
        'name' => 'orbit',
        'slug' => 'orbit',
        'repository_url' => 'git@example.test:orbit.git',
        'default_branch' => 'main',
    ]);
    $process = $project->processDefinitions()->create(['name' => 'worker', 'environments' => ['development'], 'spec' => ['command' => ['php', 'artisan', 'queue:work']]]);
    $schedule = $project->scheduleDefinitions()->create(['name' => 'cleanup', 'environments' => ['development'], 'spec' => ['expression' => '* * * * *']]);
    $create = require database_path('migrations/2026_10_06_000000_create_task_definitions_table.php');
    $create->up();
    DB::table('task_definitions')->insert([
        'project_id' => $project->id,
        'name' => 'retired',
        'title' => 'Retired',
        'brief' => 'Never executed.',
        'parameters' => '[]',
        'status' => 'backlog',
        'phases' => '[]',
        'subtasks' => '[]',
    ]);
    $migration = require database_path('migrations/2026_10_15_052820_drop_task_definitions_table.php');

    $migration->up();
    $migration->up();
    $migration->down();

    expect(Schema::hasTable('task_definitions'))->toBeFalse()
        ->and($project->fresh())->not->toBeNull()
        ->and($process->fresh()?->spec)->toBe($process->spec)
        ->and($schedule->fresh()?->spec)->toBe($schedule->spec);
});
