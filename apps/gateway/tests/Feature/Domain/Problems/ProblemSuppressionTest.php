<?php

declare(strict_types=1);

use App\Domain\Problems\ProblemCollector;
use App\Domain\Problems\ProblemSource;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\Activity;
use App\Models\ProblemFingerprint;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Support\TestOrbitHome;

it('ships problem suppression config beside tasks with empty fingerprint and path prefix lists', function (): void {
    expect(config('orbit.problems'))->toBe([
        'suppressed_fingerprints' => [],
        'suppressed_path_prefixes' => [],
    ])->and(array_key_exists('tasks', config('orbit')))->toBeTrue()
        ->and(array_key_exists('problems', config('orbit')))->toBeTrue();
});

it('does not suppress a source path when the configured prefix is empty', function (): void {
    config()->set('orbit.problems.suppressed_fingerprints', []);
    config()->set('orbit.problems.suppressed_path_prefixes', ['']);
    problem_suppression_sandbox();
    problem_suppression_append('');
    problem_suppression_collect();
    problem_suppression_append(problem_suppression_record(
        'RuntimeException',
        base_path().'/app/Infrastructure/Example/Driver.php',
        'App\\Infrastructure\\Example\\Driver->run()',
        'req-empty',
        'Example subscription ended.',
        '2026-10-01 00:01:06',
    ));

    expect(problem_suppression_collect())->toBe(0)
        ->and(ProblemFingerprint::query()->count())->toBe(1)
        ->and(ProblemFingerprint::query()->sole()->evidence['source_path'])->toBe('app/Infrastructure/Example/Driver.php');
});

it('does not suppress a shortened log fingerprint when no path prefix is configured', function (): void {
    config()->set('orbit.problems.suppressed_fingerprints', []);
    config()->set('orbit.problems.suppressed_path_prefixes', []);
    problem_suppression_project();
    $short = problem_suppression_log('log#'.substr(hash('sha256', 'empty-prefixes'), 0, 12), [
        'log_excerpt' => 'shortened',
        ...problem_suppression_windows(10),
    ]);
    $frame = problem_suppression_fingerprint(
        'log|RuntimeException|app/Infrastructure/Example/Driver.php:App\\Infrastructure\\Example\\Driver->run',
        10,
        [
            'log_excerpt' => 'driver',
            ...problem_suppression_windows(10, '2026-09-29 08:00:00'),
        ],
        Carbon::parse('2026-09-29 08:00:00', 'UTC'),
    );

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(2)
        ->and($short->refresh()->task_group_id)->not->toBeNull()
        ->and($frame->refresh()->task_group_id)->not->toBeNull();
});

it('still applies the 14 day cancel mute when a fingerprint is suppressed', function (): void {
    config()->set('orbit.problems.suppressed_fingerprints', [
        'activity|instance:clone|instance.clone_failed',
    ]);
    $project = problem_suppression_project();
    $task = problem_suppression_cancelled($project->id, 'Cancelled noise');
    $fingerprint = problem_suppression_fingerprint('activity|instance:clone|instance.clone_failed', 10, [
        'error_message' => 'Clone failed',
        'request_ids' => ['req-1'],
        'assistance_task_ids' => [99],
        ...problem_suppression_windows(10),
    ]);
    $fingerprint->task_group_id = $task->id;
    $fingerprint->save();

    expect(Artisan::call('problems:file'))->toBe(0);

    $muted = $fingerprint->refresh();

    expect(Task::topLevel()->count())->toBe(1)
        ->and($muted->task_group_id)->toBe($task->id)
        ->and($muted->filed_at)->toBeNull()
        ->and($muted->muted_until?->utc()->format('Y-m-d H:i:s'))->toBe('2026-09-30 08:30:00')
        ->and($muted->occurrences)->toBe(0)
        ->and($muted->evidence)->not->toHaveKey('request_ids')
        ->and($muted->evidence['assistance_task_ids'] ?? null)->toBe([99]);
});

function problem_suppression_sandbox(): void
{
    $path = TestOrbitHome::scratch('problem-suppression');
    mkdir($path.'/logs', 0700, true);
    app()->useStoragePath($path);
    app(TaskExtensionState::class)->enable();
}

function problem_suppression_activity(string $command, ?string $errorCode, ?int $exitCode, ?string $requestId = null): Activity
{
    return Activity::query()->create([
        'log_name' => 'commands',
        'description' => $command,
        'request_id' => $requestId ?? (string) Str::uuid(),
        'command' => $command,
        'status' => 'failed',
        'exit_code' => $exitCode,
        'error_code' => $errorCode,
        'properties' => [
            'path' => '/resources/'.$command,
            'error_message' => 'token=supersecretvalue',
        ],
    ]);
}

function problem_suppression_activity_at(
    string $command,
    ?string $errorCode,
    ?int $exitCode,
    Carbon $createdAt,
    ?string $requestId = null,
): Activity {
    $activity = problem_suppression_activity($command, $errorCode, $exitCode, $requestId);
    $activity->forceFill(['created_at' => $createdAt])->save();

    return $activity;
}

function problem_suppression_append(string $contents): void
{
    $path = storage_path('logs/laravel.log');
    $directory = dirname($path);

    if (! is_dir($directory)) {
        mkdir($directory, 0700, true);
    }

    file_put_contents($path, $contents, FILE_APPEND);
}

function problem_suppression_record(
    string $class,
    string $framePath,
    string $call,
    string $requestId,
    string $message,
    ?string $recordedAt = null,
): string {
    $loggedClass = str_replace('\\', '\\\\', $class);
    $loggedCall = str_replace('\\', '\\\\', $call);
    $timezone = config('app.timezone');
    $stamp = $recordedAt ?? now()->timezone(is_string($timezone) && $timezone !== '' ? $timezone : 'UTC')->format('Y-m-d H:i:s');

    return '['.$stamp.'] testing.ERROR: '.$message.' {"exception":"[object] ('.$loggedClass.'(code: 0): '.$message.' at '.$framePath.':1)
[stacktrace]
#0 '.$framePath.'(1): '.$loggedCall.'
#1 /tmp/vendor/framework.php(1): ignore()
#2 {main}
","request_id":"'.$requestId.'"} 
';
}

function problem_suppression_project(): Project
{
    app(TaskExtensionState::class)->enable();

    return Project::query()->create([
        'name' => 'Orbit',
        'slug' => 'orbit',
        'repository_url' => 'https://example.test/orbit.git',
        'default_branch' => 'main',
    ]);
}

/**
 * @return array{observation_times: list<string>, observation_counts: list<int>}
 */
function problem_suppression_windows(int $count, string $start = '2026-09-30 10:00:00'): array
{
    $origin = Carbon::parse($start, 'UTC');
    $times = [];

    for ($index = 0; $index < $count; $index++) {
        $times[] = $origin->copy()->addMinutes($index * 5)->format('Y-m-d\TH:i:s.u\Z');
    }

    return [
        'observation_times' => $times,
        'observation_counts' => array_fill(0, $count, 1),
    ];
}

/**
 * @param  array<string, mixed>  $evidence
 */
function problem_suppression_fingerprint(
    string $fingerprint,
    int $occurrences,
    array $evidence = [],
    ?Carbon $firstSeen = null,
    ?Carbon $lastSeen = null,
): ProblemFingerprint {
    $source = match (true) {
        str_starts_with($fingerprint, 'doctor|') => ProblemSource::Doctor,
        str_starts_with($fingerprint, 'log|') => ProblemSource::Log,
        str_starts_with($fingerprint, 'assist|') => ProblemSource::Assist,
        default => ProblemSource::Activity,
    };

    return ProblemFingerprint::query()->create([
        'fingerprint' => $fingerprint,
        'source' => $source,
        'first_seen' => $firstSeen ?? Carbon::parse('2026-09-30 10:00:00'),
        'last_seen' => $lastSeen ?? Carbon::parse('2026-09-30 10:14:00'),
        'occurrences' => $occurrences,
        'evidence' => $evidence,
    ]);
}

/**
 * @param  array<string, mixed>  $evidence
 */
function problem_suppression_log(string $fingerprint, array $evidence, ?int $taskGroupId = null): ProblemFingerprint
{
    return ProblemFingerprint::query()->create([
        'fingerprint' => $fingerprint,
        'source' => ProblemSource::Log,
        'first_seen' => Carbon::parse('2026-09-30 10:00:00', 'UTC'),
        'last_seen' => Carbon::parse('2026-09-30 10:45:00', 'UTC'),
        'occurrences' => 10,
        'evidence' => $evidence,
        'task_group_id' => $taskGroupId,
    ]);
}

function problem_suppression_cancelled(int $projectId, string $title): Task
{
    $task = Task::topLevel()->create([
        'project_id' => $projectId,
        'title' => $title,
        'brief' => 'The operator cancelled this group.',
        'status' => TaskGroupStatus::Cancelled,
    ]);
    Task::topLevel()->whereKey($task->id)->update(['updated_at' => '2026-09-16 08:30:00']);

    return $task;
}

function problem_suppression_collect(): int
{
    return app(ProblemCollector::class)->collect() === [] ? 0 : 1;
}
