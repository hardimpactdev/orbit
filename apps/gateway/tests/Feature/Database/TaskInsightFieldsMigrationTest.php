<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function insight_fields_group(int $projectId, string $title, string $status, string $createdAt): int
{
    return DB::table('tasks')->insertGetId([
        'project_id' => $projectId, 'title' => $title, 'brief' => 'Brief.', 'status' => $status, 'orchestration' => 'tick',
        'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi', 'implementer_model' => 'model-a', 'reviewer_model' => 'model-a',
        'assistance_requested' => false, 'created_at' => $createdAt, 'updated_at' => '2026-10-27 00:00:00',
    ]);
}

/** @param  array<string, mixed>  $input */
function insight_fields_command(string $command, string $path, array $input, string $at, string $status = 'succeeded'): void
{
    DB::table('activity_log')->insert([
        'log_name' => 'commands', 'description' => $command, 'event' => 'command', 'command' => $command, 'status' => $status,
        'properties' => json_encode(['method' => 'POST', 'path' => $path, 'input' => $input], JSON_THROW_ON_ERROR),
        'request_id' => (string) str()->uuid(), 'created_at' => $at, 'updated_at' => $at,
    ]);
}

it('fills lifecycle times of ended tasks from exact records and leaves the rest null', function (): void {
    $migration = require database_path('migrations/2027_01_04_000000_add_task_insight_fields.php');
    $migration->down();
    expect(Schema::hasColumns('tasks', ['ready_at', 'ended_at', 'merged_at']))->toBeFalse()
        ->and(Schema::hasColumn('agent_threads', 'cost_usd'))->toBeFalse();

    $projectId = DB::table('projects')->insertGetId([
        'name' => 'Backfill', 'code' => 'BCK', 'slug' => 'backfill', 'type' => 'laravel-app', 'source_access' => 'github_app',
        'repository_url' => 'git@example.test:backfill.git', 'repository_identity' => 'example.test/backfill', 'default_branch' => 'main',
    ]);
    $created = insight_fields_group($projectId, 'Created as todo', 'completed', '2026-10-01 09:00:00');
    $promoted = insight_fields_group($projectId, 'Promoted', 'cancelled', '2026-10-02 09:00:00');
    $personMerged = insight_fields_group($projectId, 'Person merged', 'completed', '2026-10-03 09:00:00');
    $unknown = insight_fields_group($projectId, 'Unknown', 'completed', '2026-10-04 09:00:00');
    $open = insight_fields_group($projectId, 'Open', 'running', '2026-10-05 09:00:00');

    insight_fields_command('tasks:create', 'api/v1/task-groups', ['title' => 'Created as todo', 'project_id' => $projectId, 'status' => 'todo'], '2026-10-01 09:00:01');
    insight_fields_command('tasks:update', "api/v1/task-groups/{$promoted}", ['status' => 'todo'], '2026-10-02 10:00:00');
    insight_fields_command('tasks:update', "api/v1/task-groups/{$promoted}", ['status' => 'todo'], '2026-10-02 11:00:00');
    insight_fields_command('tasks:cancel', "api/v1/task-groups/{$promoted}/cancel", [], '2026-10-02 12:00:00', 'failed');
    insight_fields_command('tasks:cancel', "api/v1/task-groups/{$promoted}/cancel", [], '2026-10-02 13:00:00');
    insight_fields_command('tasks:update', "api/v1/task-groups/{$open}", ['status' => 'todo'], '2026-10-05 10:00:00');
    DB::table('activity_log')->insert([
        'log_name' => 'tasks', 'description' => 'pull request merged', 'subject_type' => 'App\Models\Task', 'subject_id' => $created,
        'command' => 'tasks:tick', 'status' => 'completed', 'request_id' => (string) str()->uuid(), 'created_at' => '2026-10-01 15:00:00',
    ]);
    DB::table('jev_decisions')->insert([
        'purpose' => 'brief_coverage', 'call_started_at' => '2026-10-03 10:00:00', 'task_group_id' => $personMerged,
        'merged_at' => '2026-10-03T14:00:00Z', 'questions' => '[]', 'input_state' => '{}',
    ]);

    $migration->up();
    $times = DB::table('tasks')->whereNull('parent_id')->orderBy('id')->get(['id', 'ready_at', 'ended_at', 'merged_at'])->keyBy('id');

    expect((array) $times[$created])->toMatchArray(['ready_at' => '2026-10-01 09:00:00', 'merged_at' => '2026-10-01 15:00:00', 'ended_at' => '2026-10-01 15:00:00'])
        ->and((array) $times[$promoted])->toMatchArray(['ready_at' => '2026-10-02 10:00:00', 'merged_at' => null, 'ended_at' => '2026-10-02 13:00:00'])
        ->and((array) $times[$personMerged])->toMatchArray(['ready_at' => null, 'merged_at' => '2026-10-03 14:00:00', 'ended_at' => '2026-10-03 14:00:00'])
        ->and((array) $times[$unknown])->toMatchArray(['ready_at' => null, 'merged_at' => null, 'ended_at' => null])
        ->and((array) $times[$open])->toMatchArray(['ready_at' => '2026-10-05 10:00:00', 'ended_at' => null])
        ->and(Schema::hasColumn('agent_threads', 'cost_usd'))->toBeTrue();
});
