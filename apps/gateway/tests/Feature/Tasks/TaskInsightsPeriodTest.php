<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-19 14:25:00', 'UTC'));
    app(TaskExtensionState::class)->enable();
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    $gateway = Node::withoutEvents(fn () => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit']));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->project = Project::query()->create(['name' => 'app', 'slug' => 'app', 'repository_url' => 'https://github.com/example/app.git', 'default_branch' => 'main', 'root' => 'public']);
});

function endedTask(string $endedAt, bool $merged): void
{
    $task = Task::topLevel()->create(['project_id' => test()->project->id, 'title' => 'T', 'brief' => 'Brief',
        'status' => $merged ? TaskGroupStatus::Completed : TaskGroupStatus::Failed]);
    DB::table('tasks')->where('id', $task->id)->update(['ended_at' => $endedAt, 'merged_at' => $merged ? $endedAt : null]);
}

it('accepts the 24h, 7d and 30d periods and refuses any other', function (string $period, int $status): void {
    $this->getJson('/api/v1/task-insights?period='.$period)->assertStatus($status);
})->with([['24h', 200], ['7d', 200], ['30d', 200], ['1h', 422], ['48h', 422], ['24', 422]]);

it('reports the 24 hours up to the end of the current UTC hour, with the 24 hours before as the previous period', function (): void {
    endedTask('2026-11-18 15:00:00', true);  // the first hour of the window
    endedTask('2026-11-19 14:59:59', false); // the current hour
    endedTask('2026-11-18 14:59:59', true);  // the previous period
    endedTask('2026-11-17 15:00:00', false); // the first hour of the previous period
    endedTask('2026-11-17 14:59:59', true);  // before both, still in the weekly trend

    $data = $this->getJson('/api/v1/task-insights?period=24h')->assertOk()->json('data');

    expect($data['period'])->toBe('24h')
        ->and($data['from'])->toBe('2026-11-18T15:00:00+00:00')
        ->and($data['to'])->toBe('2026-11-19T15:00:00+00:00')
        ->and([$data['current']['tasks'], $data['current']['merged']])->toBe([2, 1])
        ->and([$data['previous']['tasks'], $data['previous']['merged']])->toBe([2, 1])
        ->and(array_sum(array_column($data['breakdowns']['project'], 'tasks')))->toBe(2)
        ->and($data['weekly'])->toHaveCount(12)
        ->and(array_sum(array_column($data['weekly'], 'tasks')))->toBe(5)
        ->and($data['daily'])->toBe([]);
});

it('counts merged and not merged tasks in the hour each ended, oldest hour first', function (): void {
    endedTask('2026-11-18 15:00:00', true);
    endedTask('2026-11-18 15:59:59', false);
    endedTask('2026-11-19 09:30:00', true);
    endedTask('2026-11-19 09:31:00', true);
    endedTask('2026-11-19 14:10:00', false);
    endedTask('2026-11-18 14:59:59', true); // the previous period, in no bucket

    $hourly = $this->getJson('/api/v1/task-insights?period=24h')->assertOk()->json('data.hourly');

    expect($hourly)->toHaveCount(24)
        ->and($hourly[0])->toBe(['hour' => '2026-11-18T15:00:00+00:00', 'merged' => 1, 'not_merged' => 1])
        ->and($hourly[18])->toBe(['hour' => '2026-11-19T09:00:00+00:00', 'merged' => 2, 'not_merged' => 0])
        ->and($hourly[23])->toBe(['hour' => '2026-11-19T14:00:00+00:00', 'merged' => 0, 'not_merged' => 1])
        ->and(array_sum(array_column($hourly, 'merged')) + array_sum(array_column($hourly, 'not_merged')))->toBe(5);
});

it('keeps hourly empty and daily whole days for 7d and 30d', function (string $period, int $days, string $from): void {
    endedTask('2026-11-19 09:30:00', true);

    $data = $this->getJson('/api/v1/task-insights?period='.$period)->assertOk()->json('data');

    expect($data['period'])->toBe($period)
        ->and($data['hourly'])->toBe([])
        ->and($data['daily'])->toHaveCount($days)
        ->and($data['from'])->toBe($from)
        ->and($data['to'])->toBe('2026-11-20T00:00:00+00:00')
        ->and(end($data['daily']))->toBe(['date' => '2026-11-19', 'merged' => 1, 'not_merged' => 0]);
})->with([['7d', 7, '2026-11-13T00:00:00+00:00'], ['30d', 30, '2026-10-21T00:00:00+00:00']]);

it('defaults to 30d', function (): void {
    $this->getJson('/api/v1/task-insights')->assertOk()->assertJsonPath('data.period', '30d')->assertJsonPath('data.hourly', []);
});
