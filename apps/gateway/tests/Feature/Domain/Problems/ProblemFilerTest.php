<?php

declare(strict_types=1);

use App\Actions\Tasks\CreateTaskGroupAction;
use App\Data\Tasks\CreateTaskGroupData;
use App\Data\Tasks\TaskInputData;
use App\Domain\Fleet\FleetNodeOutcome;
use App\Domain\Fleet\FleetRolloutStatus;
use App\Domain\GatewayReleases\GatewayReleaseAutomation;
use App\Domain\Instances\InstanceState;
use App\Domain\Problems\ProblemCollector;
use App\Domain\Problems\ProblemSource;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Models\Activity;
use App\Models\FleetRollout;
use App\Models\FleetRolloutNode;
use App\Models\GatewayRelease;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Node;
use App\Models\ProblemFingerprint;
use App\Models\Project;
use App\Models\Task;
use App\Models\UpCloudTaskVmImageBuild;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

it('files a repro-first group when a fingerprint is over the threshold', function (): void {
    $project = problem_filer_project();
    $fingerprint = problem_filer_fingerprint('activity|instance:clone|instance.clone_failed', 10, [
        'error_message' => 'Clone failed',
        'request_ids' => ['req-1', 'req-2'],
        'activity_ids' => [11, 12],
        'paths' => ['/resources/instance:clone'],
        ...problem_filer_windows(10),
    ], Carbon::parse('2026-09-30 10:00:00', 'UTC'), Carbon::parse('2026-09-30 10:45:00', 'UTC'));

    expect(Artisan::call('problems:file'))->toBe(0);

    $group = Task::topLevel()->sole();
    $subtasks = $group->tasks()->get();
    $filed = $fingerprint->refresh();

    expect($group->project_id)->toBe($project->id)
        ->and($group->status)->toBe(TaskGroupStatus::Backlog)
        ->and($group->title)->toBe('instance:clone failed with instance.clone_failed')
        ->and($group->brief)->toBe(problem_filer_brief())
        ->and($subtasks)->toHaveCount(2)
        ->and($subtasks[0]->title)->toBe('Document the owning page')
        ->and($subtasks[0]->brief)->toBe('Update the page that owns the failing behaviour so it describes the fixed behaviour, or confirm that no page changes.')
        ->and($subtasks[0]->deliverables)->toBe([[
            'id' => 'docs',
            'type' => 'review',
            'description' => 'The owning page matches the fix, or no page changes.',
        ]])
        ->and($subtasks[1]->title)->toBe('Reproduce the failure and fix it')
        ->and($subtasks[1]->brief)->toBe('Add a test that fails on the start commit for the symptom in the group brief. Then fix the root cause so it passes.')
        ->and($subtasks[1]->deliverables)->toBe([[
            'id' => 'test',
            'type' => 'review',
            'description' => 'Replace this deliverable with a scoped fails_on_base command before moving the task to Todo.',
        ]])
        ->and($filed->task_group_id)->toBe($group->id)
        ->and($filed->filed_at)->not->toBeNull()
        ->and($filed->muted_until)->toBeNull()
        ->and($filed->occurrences)->toBe(0)
        ->and($filed->first_seen)->toBeNull()
        ->and($filed->last_seen)->toBeNull()
        ->and($filed->evidence)->not->toHaveKey('request_ids')
        ->and($filed->evidence)->not->toHaveKey('activity_ids')
        ->and($filed->evidence)->not->toHaveKey('paths')
        ->and($filed->evidence)->not->toHaveKey('observation_times')
        ->and($filed->evidence)->not->toHaveKey('observation_counts')
        ->and($filed->evidence)->not->toHaveKey('counted_blocks')
        ->and($filed->evidence['error_message'] ?? null)->toBe('Clone failed');

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(1);
});

it('files nothing from an interrupted filing and files the problem once on the next run', function (): void {
    problem_filer_project();
    $fingerprint = problem_filer_fingerprint('activity|instance:clone|instance.clone_failed', 10, [
        'error_message' => 'Clone failed',
        ...problem_filer_windows(10),
    ]);
    ProblemFingerprint::saving(static function (ProblemFingerprint $row): void {
        if ($row->task_group_id !== null) {
            throw new RuntimeException('Killed after the group insert, before the fingerprint write.');
        }
    });

    try {
        expect(Artisan::call('problems:file'))->toBe(1)
            ->and(Task::query()->withoutGlobalScope('subtask')->count())->toBe(0)
            ->and($fingerprint->refresh()->task_group_id)->toBeNull()
            ->and($fingerprint->filed_at)->toBeNull()
            ->and($fingerprint->occurrences)->toBe(10);
    } finally {
        ProblemFingerprint::flushEventListeners();
    }

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(1)
        ->and($fingerprint->refresh()->task_group_id)->toBe(Task::topLevel()->sole()->id);
});

it('files a filed template with occurrence history and a review placeholder instead of an unscoped command', function (): void {
    problem_filer_project();
    problem_filer_fingerprint(
        'log|RuntimeException|app/Domain/Tasks/TaskScheduler.php:App\\Domain\\Tasks\\TaskScheduler->tick',
        3,
        [
            'log_excerpt' => 'T3 subscription ended.',
            'request_ids' => ['req-1', 'req-2'],
            'observation_times' => [
                '2026-10-01T00:16:06.000000Z',
                '2026-10-01T00:01:06.000000Z',
                '2026-10-01T00:06:06.000000Z',
            ],
            'observation_counts' => [2, 129, 1],
        ],
        Carbon::parse('2026-10-01 00:01:06', 'UTC'),
        Carbon::parse('2026-10-01 00:16:06', 'UTC'),
    );

    expect(Artisan::call('problems:file'))->toBe(0);

    $group = Task::topLevel()->sole();
    $subtasks = $group->tasks()->get();

    expect($group->status)->toBe(TaskGroupStatus::Backlog)
        ->and($group->brief)->toBe(implode("\n\n", [
            'Filed by the outer loop.',
            "Symptom\nT3 subscription ended.",
            "Fingerprint\nlog|RuntimeException|app/Domain/Tasks/TaskScheduler.php:App\\Domain\\Tasks\\TaskScheduler->tick",
            "First seen\n2026-10-01 00:01:06 UTC",
            "Last seen\n2026-10-01 00:16:06 UTC",
            "Count\n3",
            "Occurrences\n2026-10-01 00:01:06 UTC 129\n2026-10-01 00:06:06 UTC 1\n2026-10-01 00:16:06 UTC 2",
            "Evidence\nRequest ids: req-1, req-2\nLog excerpt: T3 subscription ended.",
            "Suspected entry point\napp/Domain/Tasks/TaskScheduler.php:App\\Domain\\Tasks\\TaskScheduler->tick",
        ]))
        ->and($subtasks)->toHaveCount(2)
        ->and($subtasks[0]->title)->toBe('Document the owning page')
        ->and($subtasks[1]->title)->toBe('Reproduce the failure and fix it')
        ->and($subtasks[1]->deliverables)->toBe([[
            'id' => 'test',
            'type' => 'review',
            'description' => 'Replace this deliverable with a scoped fails_on_base command before moving the task to Todo.',
        ]]);

    foreach ($subtasks as $subtask) {
        expect($subtask->brief)->not->toContain('T3 subscription ended.');

        foreach ($subtask->deliverables as $deliverable) {
            expect($deliverable)->not->toHaveKey('command')
                ->not->toHaveKey('directory')
                ->not->toHaveKey('fails_on_base')
                ->not->toHaveKey('paths');
        }
    }
});

it('renders the known source path in the filed template for a shortened log key before clearing it', function (): void {
    problem_filer_project();
    $sourcePath = 'app/Domain/Tasks/TaskScheduler.php';
    $key = 'log|'.str_repeat('VeryLongNamespace\\', 20).'RuntimeException|'.$sourcePath.':App\\Domain\\Tasks\\TaskScheduler->tick';
    $shortened = 'log#'.substr(hash('sha256', $key), 0, 12);
    $fingerprint = problem_filer_fingerprint($shortened, 4, [
        'source_path' => $sourcePath,
        'log_excerpt' => 'T3 subscription ended.',
        'request_ids' => ['req-shortened'],
        ...problem_filer_windows(4, '2026-10-01 00:00:01'),
    ], Carbon::parse('2026-10-01 00:00:01', 'UTC'), Carbon::parse('2026-10-01 00:15:01', 'UTC'));

    expect(Artisan::call('problems:file'))->toBe(0);

    $group = Task::topLevel()->sole();

    expect($group->status)->toBe(TaskGroupStatus::Backlog)
        ->and($group->brief)->toContain(
            "Fingerprint\n{$shortened}",
            "Occurrences\n2026-10-01 00:00:01 UTC 1\n2026-10-01 00:05:01 UTC 1\n2026-10-01 00:10:01 UTC 1\n2026-10-01 00:15:01 UTC 1",
            'Request ids: req-shortened',
            "Suspected entry point\n{$sourcePath}",
        )
        ->not->toContain("Suspected entry point\n{$shortened}")
        ->and($fingerprint->refresh()->task_group_id)->toBe($group->id)
        ->and($fingerprint->evidence)->not->toHaveKey('source_path');
});

it('keeps the newest twenty UTC windows in the filed template', function (): void {
    problem_filer_project();
    $windows = problem_filer_windows(21);
    problem_filer_fingerprint('activity|instance:clone|instance.clone_failed', 21, [
        'error_message' => 'Clone failed',
        'observation_times' => array_reverse($windows['observation_times']),
        'observation_counts' => array_reverse(range(1, 21)),
    ]);

    expect(Artisan::call('problems:file'))->toBe(0);

    $brief = Task::topLevel()->sole()->brief;
    $history = explode("\n\nEvidence\n", explode("Occurrences\n", $brief)[1])[0];

    expect(explode("\n", $history))->toHaveCount(20)
        ->and($history)->toStartWith('2026-09-30 10:05:00 UTC 2')
        ->toEndWith('2026-09-30 11:40:00 UTC 21')
        ->not->toContain('2026-09-30 10:00:00 UTC 1')
        ->and($brief)->toContain("Evidence\nnone")
        ->not->toContain('Request ids:');
});

it('shows none in the filed template when there is no valid occurrence history', function (mixed $times, mixed $counts): void {
    problem_filer_project();
    problem_filer_fingerprint('activity|instance:clone|instance.clone_failed', 10, [
        'error_message' => 'Clone failed',
        'observation_times' => $times,
        'observation_counts' => $counts,
    ]);

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->sole()->brief)->toContain("Occurrences\nnone\n\nEvidence\nnone");
})->with([
    'no times' => [null, []],
    'no counts' => [[], null],
    'empty sample' => [[], []],
    'invalid sample' => [
        ['not-a-timestamp', '', 42, '2026-09-30T10:00:00Z', '2026-09-30T10:05:00Z', '2026-09-30T10:10:00Z'],
        [1, 1, 1, 0, '1'],
    ],
]);

it('converts occurrence times to UTC in the filed template without changing their counts', function (): void {
    problem_filer_project();
    problem_filer_fingerprint('activity|instance:clone|instance.clone_failed', 10, [
        'error_message' => 'Clone failed',
        'observation_times' => ['2026-09-30T12:00:01+02:00', '2026-09-30T11:05:01+01:00'],
        'observation_counts' => [129, 2],
    ]);

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->sole()->brief)->toContain("Occurrences\n2026-09-30 10:00:01 UTC 129\n2026-09-30 10:05:01 UTC 2");
});

it('files a repro-first group when doctor sees the same issue twice', function (): void {
    problem_filer_project();
    $fingerprint = problem_filer_fingerprint('doctor|node.lifecycle_not_active|node|4', 2, [
        'summary' => 'Node 4 is still provisioning.',
        'expected' => 'active',
        'observed' => 'provisioning',
        'observation_times' => [
            '2026-09-30T10:00:00Z',
            '2026-09-30T10:10:00Z',
        ],
        'observation_counts' => [1, 1],
    ], Carbon::parse('2026-09-30 10:00:00'), Carbon::parse('2026-09-30 10:10:00'));

    expect(Artisan::call('problems:file'))->toBe(0);

    $group = Task::topLevel()->sole();

    expect($group->status)->toBe(TaskGroupStatus::Backlog)
        ->and($group->title)->toBe('Doctor node.lifecycle_not_active on node 4')
        ->and($group->brief)->toContain('Symptom', 'Node 4 is still provisioning.', 'Expected: active', 'Observed: provisioning')
        ->and($group->brief)->toContain('Suspected entry point', 'node 4 node.lifecycle_not_active')
        ->and($fingerprint->refresh()->task_group_id)->toBe($group->id)
        ->and($group->tasks()->get()[1]->deliverables[0]['type'] ?? null)->toBe('review');
});

it('files a repro-first group when three hits land in two quarter hours', function (): void {
    problem_filer_project();
    $fingerprint = problem_filer_fingerprint('activity|route:publish|route.publish_failed', 3, [
        'error_message' => 'Publish failed',
        'observation_times' => [
            '2026-09-30T10:00:00Z',
            '2026-09-30T10:07:00Z',
            '2026-09-30T10:15:00Z',
        ],
        'observation_counts' => [1, 1, 1],
    ]);

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(1)
        ->and($fingerprint->refresh()->task_group_id)->not->toBeNull();
});

it('files a repro-first group for nothing below the threshold', function (): void {
    problem_filer_project();
    $belowBurst = problem_filer_fingerprint('activity|instance:clone|instance.clone_failed', 2, [
        'error_message' => 'Clone failed',
        ...problem_filer_windows(2),
    ]);
    $sameQuarter = problem_filer_fingerprint('activity|instance:delete|instance.delete_failed', 3, [
        'error_message' => 'Delete failed',
        ...problem_filer_windows(3),
    ]);
    $doctor = problem_filer_fingerprint('doctor|node.lifecycle_not_active|node|4', 2, [
        'summary' => 'Node 4 is still provisioning.',
        'observation_times' => ['2026-09-30T10:00:00Z', '2026-09-30T10:09:00Z'],
        'observation_counts' => [1, 1],
    ], Carbon::parse('2026-09-30 10:00:00'), Carbon::parse('2026-09-30 10:09:00'));

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(0)
        ->and($belowBurst->refresh()->occurrences)->toBe(2)
        ->and($belowBurst->task_group_id)->toBeNull()
        ->and($sameQuarter->refresh()->task_group_id)->toBeNull()
        ->and($sameQuarter->occurrences)->toBe(3)
        ->and($doctor->refresh()->occurrences)->toBe(2)
        ->and($doctor->task_group_id)->toBeNull();
});

it('files a repro-first group for nothing after one log burst', function (): void {
    problem_filer_project();
    $fingerprint = problem_filer_fingerprint(
        'log|RuntimeException|app/Domain/Tasks/TaskScheduler.php:App\\Domain\\Tasks\\TaskScheduler->tick',
        1,
        [
            'log_excerpt' => 'T3 subscription ended.',
            'observation_times' => ['2026-10-01T00:01:06.000000Z'],
            'observation_counts' => [129],
        ],
        Carbon::parse('2026-10-01 00:01:06', 'UTC'),
        Carbon::parse('2026-10-01 00:01:06', 'UTC'),
    );

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(0)
        ->and($fingerprint->refresh()->occurrences)->toBe(1)
        ->and($fingerprint->task_group_id)->toBeNull()
        ->and($fingerprint->evidence['observation_counts'])->toBe([129]);
});

it('drops a legacy log burst read across several collector times without filing it', function (): void {
    $this->travelTo(Carbon::parse('2026-10-02 16:00:00', 'UTC'));
    $project = problem_filer_project();
    $task = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Already filed',
        'brief' => 'The operator has not finished with this group.',
        'status' => TaskGroupStatus::Backlog,
    ]);
    $mutedUntil = Carbon::parse('2026-10-14 12:00:00', 'UTC');
    $filedAt = Carbon::parse('2026-10-01 01:00:00', 'UTC');
    $linked = problem_filer_fingerprint(
        'log|RuntimeException|app/Domain/Tasks/TaskScheduler.php:App\\Domain\\Tasks\\TaskScheduler->tick',
        129,
        [
            'log_excerpt' => 'T3 subscription ended.',
            'assistance_task_ids' => [44],
            'observation_times' => [
                '2026-10-02T15:00:00.000000Z',
                '2026-10-02T15:10:00.000000Z',
                '2026-10-02T15:20:00.000000Z',
            ],
        ],
        Carbon::parse('2026-10-02 15:00:00', 'UTC'),
        Carbon::parse('2026-10-02 15:20:00', 'UTC'),
    );
    $linked->task_group_id = $task->id;
    $linked->muted_until = $mutedUntil;
    $linked->filed_at = $filedAt;
    $linked->save();
    $unparsed = problem_filer_fingerprint('activity|instance:clone|instance.clone_failed', 129, [
        'error_message' => 'Clone failed',
        'observation_times' => ['not-a-timestamp'],
    ]);

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(1)
        ->and($linked->refresh()->task_group_id)->toBe($task->id)
        ->and($linked->muted_until?->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-14 12:00:00')
        ->and($linked->filed_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-01 01:00:00')
        ->and($linked->occurrences)->toBe(0)
        ->and($linked->first_seen)->toBeNull()
        ->and($linked->last_seen)->toBeNull()
        ->and($linked->evidence['assistance_task_ids'])->toBe([44])
        ->and($linked->evidence)->not->toHaveKey('observation_times')
        ->and($linked->evidence['observation_counts'])->toBe([])
        ->and($linked->evidence['counted_blocks'])->toBe([])
        ->and($unparsed->refresh()->occurrences)->toBe(0)
        ->and($unparsed->task_group_id)->toBeNull()
        ->and($unparsed->evidence['counted_blocks'])->toBe([]);
});

it('files a legacy doctor episode when two collector times are ten minutes apart', function (): void {
    problem_filer_project();
    problem_filer_fingerprint('doctor|node.lifecycle_not_active|node|4', 2, [
        'summary' => 'Node 4 is still provisioning.',
        'observation_times' => ['2026-09-30T10:00:00Z', '2026-09-30T10:10:00Z'],
    ], Carbon::parse('2026-09-30 10:00:00', 'UTC'), Carbon::parse('2026-09-30 10:10:00', 'UTC'));

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(1)
        ->and(Task::topLevel()->sole()->brief)->toContain("Occurrences\n2026-09-30 10:00:00 UTC 1\n2026-09-30 10:10:00 UTC 1");
});

it('files a repro-first group for nothing while its task is still open', function (string $status): void {
    $project = problem_filer_project();
    $task = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Already filed',
        'brief' => 'The operator has not finished with this group.',
        'status' => $status,
    ]);
    $fingerprint = problem_filer_fingerprint('activity|instance:clone|instance.clone_failed', 10, [
        'error_message' => 'Clone failed',
        ...problem_filer_windows(10),
    ]);
    $fingerprint->task_group_id = $task->id;
    $fingerprint->save();

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(1)
        ->and($fingerprint->refresh()->task_group_id)->toBe($task->id)
        ->and($fingerprint->filed_at)->toBeNull()
        ->and($fingerprint->muted_until)->toBeNull()
        ->and($fingerprint->occurrences)->toBe(10);
})->with(['backlog', 'todo', 'reserved', 'running', 'reviewing', 'settling']);

it('files a repro-first group for nothing muted after a cancel', function (): void {
    $project = problem_filer_project();
    $task = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Cancelled noise',
        'brief' => 'The operator cancelled this group.',
        'status' => TaskGroupStatus::Cancelled,
    ]);
    Task::topLevel()->whereKey($task->id)->update(['updated_at' => '2026-09-16 08:30:00']);
    $fingerprint = problem_filer_fingerprint('activity|instance:clone|instance.clone_failed', 10, [
        'error_message' => 'Clone failed',
        'request_ids' => ['req-1'],
        'log_excerpt' => 'Clone failed',
        'assistance_task_ids' => [99],
        ...problem_filer_windows(10),
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
        ->and($muted->first_seen)->toBeNull()
        ->and($muted->evidence)->not->toHaveKey('request_ids')
        ->and($muted->evidence)->not->toHaveKey('log_excerpt')
        ->and($muted->evidence)->not->toHaveKey('observation_times')
        ->and($muted->evidence['assistance_task_ids'] ?? null)->toBe([99]);

    $this->travel(1)->days();

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(1)
        ->and($fingerprint->refresh()->muted_until?->utc()->format('Y-m-d H:i:s'))->toBe('2026-09-30 08:30:00');
});

it('files a repro-first group for nothing while a cancel mute is still in force', function (): void {
    $this->travelTo(Carbon::parse('2026-09-30 12:00:00', 'UTC'));
    $project = problem_filer_project();
    $task = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Cancelled noise',
        'brief' => 'The operator cancelled this group.',
        'status' => TaskGroupStatus::Cancelled,
    ]);
    $mutedUntil = Carbon::parse('2026-10-14 12:00:00', 'UTC');
    $fingerprint = problem_filer_fingerprint('activity|instance:clone|instance.clone_failed', 10, [
        'error_message' => 'Clone failed again',
        ...problem_filer_windows(10),
    ], Carbon::parse('2026-09-30 10:00:00', 'UTC'), Carbon::parse('2026-09-30 10:45:00', 'UTC'));
    $fingerprint->task_group_id = $task->id;
    $fingerprint->muted_until = $mutedUntil;
    $fingerprint->save();

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(1)
        ->and($fingerprint->refresh()->task_group_id)->toBe($task->id)
        ->and($fingerprint->filed_at)->toBeNull()
        ->and($fingerprint->occurrences)->toBe(10)
        ->and($fingerprint->muted_until?->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-14 12:00:00');

    $this->travelTo($mutedUntil->copy()->addSecond());

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(2)
        ->and($fingerprint->refresh()->task_group_id)->not->toBe($task->id)
        ->and($fingerprint->muted_until)->toBeNull()
        ->and($fingerprint->occurrences)->toBe(0);
});

it('files a repro-first group for nothing muted after a completed or failed task', function (string $status): void {
    $project = problem_filer_project();
    $task = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Ended',
        'brief' => 'This group already ended.',
        'status' => $status,
    ]);
    Task::topLevel()->whereKey($task->id)->update(['updated_at' => '2026-09-20 12:00:00']);
    $fingerprint = problem_filer_fingerprint('log|RuntimeException|app/Domain/Tasks/TaskScheduler.php:tick', 10, [
        'log_excerpt' => 'The scheduler blew up',
        ...problem_filer_windows(10),
    ]);
    $fingerprint->task_group_id = $task->id;
    $fingerprint->save();

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(1)
        ->and($fingerprint->refresh()->muted_until?->utc()->format('Y-m-d H:i:s'))->toBe('2026-09-27 12:00:00')
        ->and($fingerprint->occurrences)->toBe(0)
        ->and($fingerprint->filed_at)->toBeNull();
})->with(['completed', 'failed']);

it('files a repro-first group for the busiest fingerprints and skips the fourth filing that day', function (): void {
    problem_filer_project();
    foreach (['activity|old:one|old.failed'] as $fingerprint) {
        ProblemFingerprint::query()->create([
            'fingerprint' => $fingerprint,
            'source' => ProblemSource::Activity,
            'occurrences' => 0,
            'evidence' => [],
            'filed_at' => now(),
        ]);
    }

    $busiest = problem_filer_fingerprint('activity|instance:deploy|instance.deploy_failed', 30, [
        'error_message' => 'Deploy failed',
        ...problem_filer_marker(),
    ], Carbon::parse('2026-09-30 11:00:00'));
    $earlier = problem_filer_fingerprint('activity|instance:clone|instance.clone_failed', 10, [
        'error_message' => 'Clone failed',
        ...problem_filer_marker(),
    ], Carbon::parse('2026-09-30 09:00:00'));
    $later = problem_filer_fingerprint('activity|instance:delete|instance.delete_failed', 10, [
        'error_message' => 'Delete failed',
        ...problem_filer_marker(),
    ], Carbon::parse('2026-09-30 10:30:00'));

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(2)
        ->and($busiest->refresh()->task_group_id)->not->toBeNull()
        ->and($earlier->refresh()->task_group_id)->not->toBeNull()
        ->and($later->refresh()->task_group_id)->toBeNull()
        ->and($later->filed_at)->toBeNull()
        ->and($later->occurrences)->toBe(10)
        ->and(Task::topLevel()->find($busiest->task_group_id)?->brief)->toContain("Evidence\nnone")
        ->and(Task::topLevel()->find($earlier->task_group_id)?->brief)->toContain("Evidence\nnone");
});

it('preserves occurrence history in the filed template when dropping Evidence lines that do not fit', function (): void {
    problem_filer_project();
    $path = str_repeat('p', 9000);
    problem_filer_fingerprint('activity|instance:clone|instance.clone_failed', 10, [
        'error_message' => 'Clone failed',
        'paths' => [$path],
        ...problem_filer_windows(10),
    ]);

    expect(Artisan::call('problems:file'))->toBe(0);

    $brief = Task::topLevel()->sole()->brief;

    expect(mb_strlen($brief))->toBeLessThanOrEqual(8000)
        ->and($brief)->toContain("Evidence\nnone")
        ->and($brief)->not->toContain($path)
        ->and($brief)->toContain('Suspected entry point', "Occurrences\n2026-09-30 10:00:00 UTC 1", "2026-09-30 10:45:00 UTC 1\n\nEvidence");
});

it('files a repro-first group for nothing when the orbit project is missing', function (): void {
    app(TaskExtensionState::class)->enable();
    $fingerprint = problem_filer_fingerprint('activity|instance:clone|instance.clone_failed', 10, [
        'error_message' => 'Clone failed',
        ...problem_filer_marker(),
    ]);

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(0)
        ->and($fingerprint->refresh()->task_group_id)->toBeNull()
        ->and($fingerprint->filed_at)->toBeNull()
        ->and($fingerprint->occurrences)->toBe(10);
});

it('does not file problems while tasks are disabled', function (): void {
    Project::query()->create([
        'name' => 'Orbit',
        'slug' => 'orbit',
        'repository_url' => 'https://example.test/orbit.git',
        'default_branch' => 'main',
    ]);
    $fingerprint = problem_filer_fingerprint('activity|instance:clone|instance.clone_failed', 10, [
        'error_message' => 'Clone failed',
    ]);

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(0)
        ->and($fingerprint->refresh()->occurrences)->toBe(10);
});

it('files a release alert on its first occurrence and ahead of higher counts', function (): void {
    problem_filer_project();
    $this->travelTo(Carbon::parse('2026-09-30 12:00:00', 'UTC'));

    foreach (['a', 'b', 'c'] as $suffix) {
        problem_filer_fingerprint("activity|instance:clone|instance.clone_{$suffix}_failed", 10, [
            'error_message' => 'Clone failed',
            ...problem_filer_windows(10),
        ]);
    }

    $release = problem_filer_fingerprint('release|rollout_halted|fleet|17|node_failed', 1, [
        'summary' => 'Node beast failed self-update.',
        'release_kind' => 'rollout_halted', 'incident_resource' => 'fleet', 'incident_attempt' => '17', 'incident_fault' => 'node_failed',
        'release_shas' => [str_repeat('a', 40)],
        'release_repository' => 'nckrtl/orbit',
        ...problem_filer_windows(1),
    ]);

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(3)
        ->and($release->refresh()->task_group_id)->not->toBeNull();

    $task = Task::topLevel()->findOrFail($release->task_group_id);

    expect($task->title)->toBe('Rollout halted for fleet, attempt 17')
        ->and($task->brief)->toContain("Symptom\nNode beast failed self-update.")
        ->and($task->brief)->toContain("Suspected entry point\nnckrtl/orbit, fleet, 17, node_failed");
});

it('does not file a release fingerprint without an occurrence', function (): void {
    problem_filer_project();
    problem_filer_fingerprint('release|release_failed|gateway|'.str_repeat('b', 40), 0, ['summary' => 'Smoke failed.']);

    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(0);
});

function problem_filer_brief(): string
{
    $history = implode("\n", [
        '2026-09-30 10:00:00 UTC 1',
        '2026-09-30 10:05:00 UTC 1',
        '2026-09-30 10:10:00 UTC 1',
        '2026-09-30 10:15:00 UTC 1',
        '2026-09-30 10:20:00 UTC 1',
        '2026-09-30 10:25:00 UTC 1',
        '2026-09-30 10:30:00 UTC 1',
        '2026-09-30 10:35:00 UTC 1',
        '2026-09-30 10:40:00 UTC 1',
        '2026-09-30 10:45:00 UTC 1',
    ]);

    return implode("\n\n", [
        'Filed by the outer loop.',
        "Symptom\nClone failed",
        "Fingerprint\nactivity|instance:clone|instance.clone_failed",
        "First seen\n2026-09-30 10:00:00 UTC",
        "Last seen\n2026-09-30 10:45:00 UTC",
        "Count\n10",
        "Occurrences\n{$history}",
        "Evidence\nRequest ids: req-1, req-2\nActivity ids: 11, 12\nPaths: /resources/instance:clone",
        "Suspected entry point\ninstance:clone",
    ]);
}

/** @return array{observation_counts: list<int>, counted_blocks: list<int>} */
function problem_filer_marker(): array
{
    return [
        'observation_counts' => [],
        'counted_blocks' => [],
    ];
}

/**
 * @return array{observation_times: list<string>, observation_counts: list<int>}
 */
function problem_filer_windows(int $count, string $start = '2026-09-30 10:00:00'): array
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

function problem_filer_project(): Project
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
 * @param  array<string, mixed>  $evidence
 */
function problem_filer_fingerprint(
    string $fingerprint,
    int $occurrences,
    array $evidence = [],
    ?Carbon $firstSeen = null,
    ?Carbon $lastSeen = null,
): ProblemFingerprint {
    $source = match (true) {
        str_starts_with($fingerprint, 'doctor|') || str_starts_with($fingerprint, 'doctor#') => ProblemSource::Doctor,
        str_starts_with($fingerprint, 'log|') || str_starts_with($fingerprint, 'log#') => ProblemSource::Log,
        str_starts_with($fingerprint, 'assist|') => ProblemSource::Assist,
        str_starts_with($fingerprint, 'release|') => ProblemSource::Release,
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

it('updates the open generated incident instead of filing another group', function (): void {
    problem_filer_project();
    $collector = app(ProblemCollector::class);
    $collector->recordRelease('release_failed|gateway|17|smoke', ['summary' => 'First failure', 'release_kind' => 'release_failed', 'incident_resource' => 'gateway', 'incident_attempt' => '17', 'incident_fault' => 'smoke']);
    expect(Artisan::call('problems:file'))->toBe(0);
    $group = Task::topLevel()->sole();
    $instructions = $group->tasks()->orderBy('position')->get(['title', 'brief', 'deliverables'])->toArray();

    $this->travel(5)->minutes();
    $collector->recordRelease('release_failed|gateway|17|smoke', ['summary' => 'Same fault with fresh evidence', 'activity_ids' => [99]]);
    expect(Artisan::call('problems:file'))->toBe(0);

    expect(Task::topLevel()->count())->toBe(1)
        ->and($group->refresh()->brief)->toContain('Same fault with fresh evidence', 'Activity ids: 99');
    expect($group->tasks()->orderBy('position')->get(['title', 'brief', 'deliverables'])->toArray())->toBe($instructions);
});

it('closes an untouched release alert only after a verified successor', function (bool $scoped): void {
    problem_filer_project();
    $failed = GatewayRelease::query()->create(['duration_ms' => 0, 'trigger' => 'auto', 'outcome' => 'failed', 'sha' => str_repeat('a', 40), 'phases' => []]);
    app(ProblemCollector::class)->recordRelease('release_failed|gateway|'.$failed->id.'|smoke', [
        'summary' => 'Smoke failed', 'release_id' => (string) $failed->id,
        'release_kind' => 'release_failed', 'incident_resource' => 'gateway', 'incident_attempt' => (string) $failed->id, 'incident_fault' => 'smoke',
    ]);
    Artisan::call('problems:file');
    $group = Task::topLevel()->sole();
    if ($scoped) {
        $group->tasks()->firstOrFail()->update(['brief' => 'Scoped regression work']);
    }
    GatewayRelease::query()->create(['duration_ms' => 0, 'trigger' => 'stage', 'outcome' => 'paused', 'sha' => str_repeat('b', 40), 'phases' => []]);
    Artisan::call('problems:file');
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Backlog);

    GatewayRelease::query()->create(['duration_ms' => 0, 'trigger' => 'auto', 'outcome' => 'verified', 'sha' => str_repeat('c', 40), 'phases' => []]);
    expect(Artisan::call('problems:file'))->toBe(0);
    expect($group->refresh()->status)->toBe($scoped ? TaskGroupStatus::Backlog : TaskGroupStatus::Cancelled);
})->with([false, true]);

it('preserves operator edits while an incident is still open', function (string $edit): void {
    problem_filer_project();
    $collector = app(ProblemCollector::class);
    $collector->recordRelease('rollout_halted|upcloud:recipe|3|template_unverified', ['summary' => 'Template missing', 'release_kind' => 'rollout_halted', 'incident_resource' => 'upcloud:recipe', 'incident_attempt' => '3', 'incident_fault' => 'template_unverified']);
    Artisan::call('problems:file');
    $group = Task::topLevel()->sole();
    if ($edit === 'title') {
        $group->update(['title' => 'Diagnose the installer failure']);
    } elseif ($edit === 'deliverable') {
        $group->tasks()->firstOrFail()->update(['deliverables' => [['id' => 'scoped', 'type' => 'review', 'description' => 'Retain installer output']]]);
    } else {
        $group->update(['status' => TaskGroupStatus::Todo]);
    }
    $original = $group->refresh()->brief;
    $collector->recordRelease('rollout_halted|upcloud:recipe|3|template_unverified', ['summary' => 'Still missing']);
    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(1)
        ->and($group->refresh()->brief)->toBe($original);
})->with(['title', 'deliverable', 'status']);

it('keeps a scheduler alert until a successor confirms its tick', function (): void {
    problem_filer_project();
    $record = GatewayRelease::query()->create(['duration_ms' => 0, 'trigger' => 'auto', 'outcome' => 'verified', 'sha' => str_repeat('a', 40), 'phases' => ['tick' => ['outcome' => 'missed']]]);
    app(ProblemCollector::class)->recordRelease('release_scheduler_silent|gateway|'.$record->id.'|scheduler_silent', [
        'summary' => 'No tick', 'release_kind' => 'release_scheduler_silent', 'incident_resource' => 'gateway', 'incident_attempt' => (string) $record->id, 'incident_fault' => 'scheduler_silent',
    ]);
    Artisan::call('problems:file');
    $group = Task::topLevel()->sole();
    $successor = GatewayRelease::query()->create(['duration_ms' => 0, 'trigger' => 'auto', 'outcome' => 'verified', 'sha' => str_repeat('b', 40), 'phases' => ['tick' => ['outcome' => 'pending']]]);
    Artisan::call('problems:file');
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Backlog);
    $successor->update(['phases' => ['tick' => ['outcome' => 'confirmed']]]);
    expect(Artisan::call('problems:file'))->toBe(0)
        ->and($group->refresh()->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($group->tasks()->get()->pluck('status')->all())->toBe([TaskStatus::Cancelled, TaskStatus::Cancelled]);
});

it('files a new episode immediately when an automatically closed incident returns', function (): void {
    problem_filer_project();
    $collector = app(ProblemCollector::class);
    $record = GatewayRelease::query()->create(['duration_ms' => 0, 'trigger' => 'auto', 'outcome' => 'verified', 'sha' => str_repeat('a', 40), 'cleanup_paused' => true, 'phases' => []]);
    $observation = ['summary' => 'Cleanup paused', 'release_kind' => 'release_cleanup_paused', 'incident_resource' => 'gateway', 'incident_attempt' => (string) $record->id, 'incident_fault' => 'cleanup_paused'];
    $collector->recordRelease('release_cleanup_paused|gateway|'.$record->id.'|cleanup_paused', $observation);
    Artisan::call('problems:file');
    $record->update(['cleanup_paused' => false]);
    Artisan::call('problems:file');
    $group = Task::topLevel()->sole();
    expect($group->status)->toBe(TaskGroupStatus::Cancelled);
    $record->update(['cleanup_paused' => true]);
    $collector->recordRelease('release_cleanup_paused|gateway|'.$record->id.'|cleanup_paused', $observation);
    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(2)
        ->and(ProblemFingerprint::query()->sole()->muted_until)->toBeNull();
});

it('does not close an unreleased branch head after another release succeeds', function (): void {
    problem_filer_project();
    $automation = app(GatewayReleaseAutomation::class);
    $head = str_repeat('c', 40);
    $automation->recordBranchHead($head, str_repeat('a', 40));
    app(ProblemCollector::class)->recordRelease('release_stalled|gateway|head:'.$head.'|branch_head_behind', [
        'summary' => 'Head C is not released', 'release_kind' => 'release_stalled', 'incident_resource' => 'gateway', 'incident_attempt' => 'head:'.$head, 'incident_fault' => 'branch_head_behind',
    ]);
    Artisan::call('problems:file');
    $group = Task::topLevel()->sole();
    $this->travel(1)->minutes();
    GatewayRelease::query()->create(['duration_ms' => 0, 'trigger' => 'auto', 'outcome' => 'verified', 'sha' => str_repeat('b', 40), 'phases' => []]);
    $automation->recordTick('released', str_repeat('b', 40));
    expect(Artisan::call('problems:file'))->toBe(0)
        ->and($group->refresh()->status)->toBe(TaskGroupStatus::Backlog);
    $automation->recordBranchHead($head, $head);
    expect(Artisan::call('problems:file'))->toBe(0)
        ->and($group->refresh()->status)->toBe(TaskGroupStatus::Cancelled);
});

it('closes a rollout wait only after its CLI becomes available', function (): void {
    problem_filer_project();
    $rollout = FleetRollout::query()->create(['order' => [], 'commit' => str_repeat('a', 40), 'status' => FleetRolloutStatus::Waiting, 'desired_state' => ['cli' => ['status' => 'pending']]]);
    app(ProblemCollector::class)->recordRelease('rollout_stalled|fleet|'.$rollout->id.'|rollout_stalled', [
        'summary' => 'CLI not published', 'release_kind' => 'rollout_stalled', 'incident_resource' => 'fleet', 'incident_attempt' => (string) $rollout->id, 'incident_fault' => 'rollout_stalled',
    ]);
    Artisan::call('problems:file');
    $group = Task::topLevel()->sole();
    $rollout->update(['status' => FleetRolloutStatus::Running]);
    Artisan::call('problems:file');
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Backlog);
    $rollout->update(['desired_state' => ['cli' => ['status' => 'available']]]);
    expect(Artisan::call('problems:file'))->toBe(0)
        ->and($group->refresh()->status)->toBe(TaskGroupStatus::Cancelled);
});

it('closes a Caddy skip only after a fresh successful publication', function (): void {
    problem_filer_project();
    $rollout = FleetRollout::query()->create(['order' => [], 'commit' => str_repeat('a', 40), 'status' => FleetRolloutStatus::Completed, 'desired_state' => []]);
    $node = Node::query()->create(['name' => 'fixture', 'public_ssh_host' => '192.0.2.10', 'user' => 'orbit', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $visit = FleetRolloutNode::query()->create(['fleet_rollout_id' => $rollout->id, 'node_id' => $node->id, 'node_name' => 'fixture', 'position' => 1, 'outcome' => FleetNodeOutcome::Converged, 'finished_at' => now(), 'evidence' => ['footprint' => ['artifacts' => ['caddy' => 'skipped']]]]);
    app(ProblemCollector::class)->recordRelease('rollout_caddy_skipped|fleet-node:'.$node->id.'|'.$rollout->id.'|rollout_caddy_skipped', [
        'summary' => 'Caddy skipped', 'release_kind' => 'rollout_caddy_skipped', 'incident_resource' => 'fleet-node:'.$node->id, 'incident_attempt' => (string) $rollout->id, 'incident_fault' => 'rollout_caddy_skipped',
    ]);
    Artisan::call('problems:file');
    $group = Task::topLevel()->sole();
    $this->travel(1)->minutes();
    $visit->update(['finished_at' => now(), 'evidence' => ['footprint' => ['artifacts' => ['caddy' => 'skipped']]]]);
    Artisan::call('problems:file');
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Backlog);
    $visit->update(['evidence' => ['footprint' => ['artifacts' => ['caddy' => 'applied'], 'skipped' => []]]]);
    expect(Artisan::call('problems:file'))->toBe(0)
        ->and($group->refresh()->status)->toBe(TaskGroupStatus::Cancelled);
});

it('normalizes SHA buckets once and adopts only untouched generated groups', function (string $edit): void {
    $this->freezeTime();
    $project = problem_filer_project();
    $recipe = 'sha256:'.str_repeat('d', 64);
    $build = UpCloudTaskVmImageBuild::query()->create(['recipe_identity' => $recipe, 'state' => TaskVmImageBuildState::Failed, 'started_at' => now(), 'finished_at' => now()]);
    $groups = [];
    foreach (['a', 'b'] as $letter) {
        $sha = str_repeat($letter, 40);
        $fingerprint = 'release|rollout_halted|machine-recipe-upcloud|'.$sha;
        $summary = $build->fenceMessage();
        $activity = Activity::query()->create(['request_id' => (string) Str::uuid(), 'log_name' => 'releases', 'description' => 'rollout_halted', 'command' => 'release:alert', 'status' => 'succeeded', 'properties' => [
            'kind' => 'rollout_halted', 'subject' => ['target' => 'machine-recipe-upcloud', 'repository' => 'nckrtl/orbit', 'sha' => $sha, 'release_id' => 'recipe-build-'.$build->id],
            'summary' => $summary, 'evidence_url' => null, 'problem' => ['fingerprint' => $fingerprint],
        ]]);
        $stamp = now()->utc()->format('Y-m-d H:i:s').' UTC';
        $brief = implode("\n\n", ['Filed by the outer loop.', "Symptom\n".$summary, "Fingerprint\n".$fingerprint, "First seen\n".$stamp, "Last seen\n".$stamp, "Count\n1", "Occurrences\n".$stamp.' 1', "Evidence\nActivity ids: ".$activity->id, "Suspected entry point\nnckrtl/orbit@".$sha.', release recipe-build-'.$build->id]);
        $data = new CreateTaskGroupData(projectId: $project->id, title: 'Rollout halted for machine-recipe-upcloud at '.substr($sha, 0, 12), brief: $brief, status: TaskGroupStatus::Backlog, tasks: [
            new TaskInputData(title: 'Document the owning page', brief: $summary, deliverables: [['id' => 'docs', 'type' => 'review', 'description' => 'The owning page matches the fix, or no page changes.']]),
            new TaskInputData(title: 'Reproduce the failure and fix it', brief: $summary, deliverables: [['id' => 'test', 'type' => 'review', 'description' => 'Replace this deliverable with a scoped fails_on_base command before moving the task to Todo.']]),
        ]);
        $group = app(CreateTaskGroupAction::class)->execute($data);
        ProblemFingerprint::query()->create(['fingerprint' => $fingerprint, 'source' => ProblemSource::Release, 'occurrences' => 0, 'filed_at' => now(), 'task_group_id' => $group->id, 'evidence' => ['summary' => $summary, 'release_repository' => 'nckrtl/orbit', 'release_id' => 'recipe-build-'.$build->id]]);
        $groups[] = $group;
    }
    if ($edit === 'brief') {
        $groups[0]->update(['brief' => $groups[0]->brief."\nPreserve my instructions."]);
    } elseif ($edit === 'api') {
        Activity::query()->create(['request_id' => (string) Str::uuid(), 'log_name' => 'commands', 'description' => 'tasks:update', 'command' => 'tasks:update', 'status' => 'succeeded', 'properties' => ['path' => 'api/task-groups/'.$groups[0]->id]]);
    }
    if (in_array($edit, ['mute', 'pending_mute'], true)) {
        $groups[1]->update(['status' => TaskGroupStatus::Cancelled]);
        if ($edit === 'mute') {
            ProblemFingerprint::query()->where('task_group_id', $groups[1]->id)->update(['muted_until' => now()->addDays(14)]);
        }
    }
    if ($edit === 'windows') {
        foreach (ProblemFingerprint::query()->orderBy('id')->get() as $index => $row) {
            $times = $index === 0 ? [now()->toISOString()] : [now()->toISOString(), now()->addMinutes(5)->toISOString()];
            $blocks = array_map(static fn (string $at): int => intdiv(Carbon::parse($at)->getTimestamp(), 300), $times);
            $row->update(['occurrences' => count($blocks), 'evidence' => [...$row->evidence, 'observation_times' => $times, 'observation_counts' => $index === 0 ? [1] : [2, 1], 'counted_blocks' => $blocks]]);
        }
    }
    $migration = require database_path('migrations/2026_11_16_000000_normalize_release_incidents.php');
    $migration->up();
    $migration->up();
    if ($edit === 'windows') {
        expect(ProblemFingerprint::query()->sole()->occurrences)->toBe(2)
            ->and(ProblemFingerprint::query()->sole()->evidence['observation_counts'])->toBe([3, 1]);
    }
    if (in_array($edit, ['mute', 'pending_mute'], true)) {
        expect(ProblemFingerprint::query()->sole()->muted_until?->getTimestamp())->toBe(now()->addDays(14)->getTimestamp());
    }
    expect(ProblemFingerprint::query()->count())->toBe(1)
        ->and(ProblemFingerprint::query()->sole()->fingerprint)->toBe('release|rollout_halted|upcloud:'.$recipe.'|'.$build->id.'|template_unverified')
        ->and(ProblemFingerprint::query()->sole()->evidence['release_shas'])->toBe([str_repeat('a', 40), str_repeat('b', 40)]);
    $build->update(['state' => TaskVmImageBuildState::Succeeded, 'template_uuid' => 'verified-template']);
    expect(Artisan::call('problems:file'))->toBe(0)
        ->and($groups[0]->refresh()->status)->toBe(in_array($edit, ['none', 'mute', 'pending_mute', 'windows'], true) ? TaskGroupStatus::Cancelled : TaskGroupStatus::Backlog)
        ->and($groups[1]->refresh()->status)->toBe(TaskGroupStatus::Cancelled)
        ->and(Task::topLevel()->count())->toBe(2);
})->with(['none', 'brief', 'api', 'mute', 'pending_mute', 'windows']);

it('does not auto-file an ambiguous historical SHA bucket', function (): void {
    problem_filer_project();
    problem_filer_fingerprint('release|release_stalled|gateway|'.str_repeat('a', 40), 1, ['summary' => 'The original head was not retained', ...problem_filer_windows(1)]);
    $migration = require database_path('migrations/2026_11_16_000000_normalize_release_incidents.php');
    $migration->up();
    expect(Artisan::call('problems:file'))->toBe(0)
        ->and(Task::topLevel()->count())->toBe(0)
        ->and(ProblemFingerprint::query()->sole()->evidence)->not->toHaveKey('resolved_at');
});

it('uses historical Doctor probe ownership without inventing synthetic resources', function (): void {
    problem_filer_project();
    $removed = problem_filer_fingerprint('doctor|instance.checkout_missing|instance|348', 0, ['summary' => 'Checkout missing']);
    $synthetic = problem_filer_fingerprint('doctor|firewall.rule_missing|firewall|orbit-metrics', 0, ['summary' => 'Metrics rule missing']);
    $migration = require database_path('migrations/2026_11_16_000000_normalize_release_incidents.php');
    $migration->up();
    Artisan::call('problems:file');
    expect($removed->refresh()->evidence['resolution'] ?? null)->toBe('resource_gone')
        ->and($synthetic->refresh()->evidence)->not->toHaveKey('resolved_at');
});

it('keeps a pending Instance commit incident when an older deployment finishes', function (): void {
    $project = problem_filer_project();
    $node = Node::query()->create(['name' => 'deploy-host', 'public_ssh_host' => '192.0.2.11', 'user' => 'orbit', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $sha = str_repeat('c', 40);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'production', 'checkout_path' => '/srv/orbit/fixture/production', 'branch' => 'main', 'status' => InstanceState::Active, 'auto_deploy' => true, 'auto_deploy_state' => ['head' => $sha, 'commit' => $sha, 'result' => 'busy', 'since' => now()->toISOString()]]);
    app(ProblemCollector::class)->recordRelease('instance_deploy_stalled|instance:'.$instance->id.'|pending:'.$sha.'|instance_deploy_stalled', [
        'summary' => 'C is waiting behind B', 'release_kind' => 'instance_deploy_stalled', 'incident_resource' => 'instance:'.$instance->id, 'incident_attempt' => 'pending:'.$sha, 'incident_fault' => 'instance_deploy_stalled',
    ]);
    Artisan::call('problems:file');
    $group = Task::topLevel()->sole();
    $this->travel(1)->minutes();
    InstanceDeployment::query()->create(['instance_id' => $instance->id, 'kind' => 'deploy', 'commit' => str_repeat('b', 40), 'started_at' => now()->subMinutes(5), 'finished_at' => now(), 'status' => 'succeeded', 'triggered_by' => 'automatic']);
    expect(Artisan::call('problems:file'))->toBe(0)
        ->and($group->refresh()->status)->toBe(TaskGroupStatus::Backlog);
    InstanceDeployment::query()->create(['instance_id' => $instance->id, 'kind' => 'deploy', 'commit' => $sha, 'started_at' => now(), 'finished_at' => now(), 'status' => 'succeeded', 'triggered_by' => 'automatic']);
    expect(Artisan::call('problems:file'))->toBe(0)
        ->and($group->refresh()->status)->toBe(TaskGroupStatus::Cancelled);
});

it('closes completed fleet incidents only after a verified later rollout', function (): void {
    problem_filer_project();
    $rollout = FleetRollout::query()->create(['order' => [], 'commit' => str_repeat('a', 40), 'status' => FleetRolloutStatus::Completed, 'desired_state' => []]);
    app(ProblemCollector::class)->recordRelease('rollout_cli_fallback|fleet|'.$rollout->id.'|rollout_cli_fallback', [
        'summary' => 'Fallback CLI', 'release_kind' => 'rollout_cli_fallback', 'incident_resource' => 'fleet', 'incident_attempt' => (string) $rollout->id, 'incident_fault' => 'rollout_cli_fallback',
    ]);
    Artisan::call('problems:file');
    $group = Task::topLevel()->sole();
    $successor = FleetRollout::query()->create(['order' => [], 'commit' => str_repeat('b', 40), 'status' => FleetRolloutStatus::Running, 'desired_state' => ['cli' => ['status' => 'available', 'reason' => null]]]);
    Artisan::call('problems:file');
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Backlog);
    $successor->update(['status' => FleetRolloutStatus::Completed]);
    expect(Artisan::call('problems:file'))->toBe(0)
        ->and($group->refresh()->status)->toBe(TaskGroupStatus::Cancelled);
});

it('retires historical Doctor incidents before filing their scoped replacement', function (string $fingerprint, string $briefForm, ?string $code): void {
    $this->freezeTime();
    problem_filer_project();
    $legacy = problem_filer_fingerprint($fingerprint, 3, ['summary' => 'Inventory failed', ...problem_filer_windows(3)], now()->subMinutes(10), now());
    expect(Artisan::call('problems:file'))->toBe(0);
    $group = Task::topLevel()->sole();
    if ($briefForm === 'symptom') {
        foreach ($group->tasks()->get() as $task) {
            $task->update(['brief' => 'Inventory failed']);
        }
    }
    $evidence = $legacy->refresh()->evidence;
    unset($evidence['generated_group_hash']);
    $legacy->update(['evidence' => $evidence]);
    $migration = require database_path('migrations/2026_11_16_000000_normalize_release_incidents.php');

    $migration->up();
    $migration->up();

    expect($legacy->refresh()->evidence['resolution'] ?? null)->toBe('doctor_identity_replaced');
    expect($legacy->evidence['doctor_code'] ?? null)->toBe($code);
    problem_filer_fingerprint('doctor|tool.inspection_failed|tool|node:1:inventory', 3, ['summary' => 'Inventory failed', ...problem_filer_windows(3)], now()->subMinutes(10), now());
    expect(Artisan::call('problems:file'))->toBe(0);
    expect($group->refresh()->status)->toBe(TaskGroupStatus::Cancelled);
    expect(Task::topLevel()->where('status', TaskGroupStatus::Backlog)->count())->toBe(1);
})->with([
    'unscoped symptom briefs' => ['doctor|tool.inspection_failed|tool|none', 'symptom', 'tool.inspection_failed'],
    'unscoped instruction briefs' => ['doctor|tool.inspection_failed|tool|none', 'instructions', 'tool.inspection_failed'],
    'hashed symptom briefs' => ['doctor#0123456789ab', 'symptom', null],
    'hashed instruction briefs' => ['doctor#0123456789ab', 'instructions', null],
]);
