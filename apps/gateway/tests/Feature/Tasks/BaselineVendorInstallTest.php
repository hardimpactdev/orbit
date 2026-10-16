<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskSetupFailure;
use App\Domain\Tasks\TaskStatus;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProjectLifecycleStep;
use App\Models\Task;
use App\Models\TaskCheck;
use Tests\Support\FakeTaskCheckRunner;

it('runs configured Project setup before the baseline check without an inferred install', function (): void {
    $project = Project::query()->create([
        'name' => 'baseline vendor install',
        'slug' => 'baseline-vendor-install',
        'repository_url' => 'git@example.test:baseline-vendor-install.git',
        'default_branch' => 'main',
        'task_check' => 'composer check',
    ]);
    $node = Node::query()->create([
        'name' => 'baseline-vendor-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.191',
        'wireguard_ip' => '10.44.0.191',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'baseline-vendor',
        'checkout_path' => '/tmp/tasks-baseline-vendor-install',
        'status' => 'reserved',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Baseline vendor install',
        'brief' => 'Install missing dependencies before check.',
        'status' => TaskGroupStatus::Running,
        'execution_mode' => TaskExecutionMode::Managed,
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'First task',
        'brief' => 'First task brief',
        'status' => TaskStatus::Running,
    ]);
    ProjectLifecycleStep::query()->create([
        'project_id' => $project->id,
        'phase' => 'setup',
        'name' => 'Project setup',
        'command' => 'echo project setup',
        'timeout_seconds' => 600,
        'position' => 1,
    ]);
    $checks = new FakeTaskCheckRunner;
    app()->instance(TaskCheckRunner::class, $checks);
    app(TaskExtensionState::class)->enable();

    app(TaskScheduler::class)->tick();

    expect($checks->setups[0])->toBe([
        [
            'name' => 'Project setup',
            'command' => 'echo project setup',
            'timeout_seconds' => 600,
        ],
    ])->and(TaskCheck::query()->sole()->kind)->toBe(TaskCheckKind::Baseline);
});

it('asks for assistance when baseline setup or the check fails without classifying dependency output', function (?string $failedStep, string $output, string $reasonText): void {
    $project = Project::query()->create([
        'name' => 'baseline install failure',
        'slug' => 'baseline-install-failure',
        'repository_url' => 'git@example.test:baseline-install-failure.git',
        'default_branch' => 'main',
        'task_check' => 'composer check',
    ]);
    $node = Node::query()->create([
        'name' => 'baseline-failure-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.192',
        'wireguard_ip' => '10.44.0.192',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'baseline-failure',
        'checkout_path' => '/tmp/tasks-baseline-install-failure',
        'status' => 'reserved',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Baseline install failure',
        'brief' => 'Report dependency installation failure.',
        'status' => TaskGroupStatus::Running,
        'execution_mode' => TaskExecutionMode::Managed,
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'First task',
        'brief' => 'First task brief',
        'status' => TaskStatus::Running,
    ]);
    app()->instance(TaskCheckRunner::class, new FakeTaskCheckRunner([
        TaskCheckReading::finished(
            1,
            str_repeat('a', 40),
            str_repeat('b', 40),
            [],
            $output,
            null,
            str_repeat('b', 40),
            $failedStep,
        ),
    ]));
    app(TaskExtensionState::class)->enable();

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $reason = $group->fresh()?->assistance_reason;

    expect($group->fresh()?->assistance_requested)->toBeTrue()
        ->and($reason)->toBeString()
        ->and($reason)->toContain($reasonText)
        ->and($reason)->not->toContain('Project dependencies appear to be missing')
        ->and($reason)->not->toContain('Composer dependency installation failed')
        ->and($reason)->not->toContain('JavaScript dependency installation failed')
        ->and($reason)->not->toContain('default branch or the task branch is broken')
        ->and(TaskCheck::query()->sole()->output)->toBe($output);
})->with([
    'setup step' => ['Install', "Could not install dependencies.\n", 'The Project setup step "Install" failed'],
    'ordinary missing-looking output' => [null, "sh: 1: vendor/bin/pest: not found\n", 'The Project baseline check failed'],
    'unexpected check error' => ['check_error', "Traceback (most recent call last):\nFileNotFoundError: missing\n", 'The Project baseline check failed'],
]);

it('keeps a baseline check error failed when the tree changed during the run', function (): void {
    $project = Project::query()->create([
        'name' => 'baseline changed error',
        'slug' => 'baseline-changed-error',
        'repository_url' => 'git@example.test:baseline-changed-error.git',
        'default_branch' => 'main',
        'task_check' => 'composer check',
    ]);
    $node = Node::query()->create([
        'name' => 'baseline-changed-error-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.193',
        'wireguard_ip' => '10.44.0.193',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'baseline-changed-error',
        'checkout_path' => '/tmp/tasks-baseline-changed-error',
        'status' => 'reserved',
    ]);
    $group = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Baseline changed error',
        'brief' => 'Report a check error even when the tree changes.',
        'status' => TaskGroupStatus::Running,
        'execution_mode' => TaskExecutionMode::Managed,
    ]);
    $group->taskable()->associate($instance);
    $group->save();
    Task::query()->create([
        'parent_id' => $group->id,
        'position' => 1,
        'title' => 'First task',
        'brief' => 'First task brief',
        'status' => TaskStatus::Running,
    ]);
    $output = "Traceback (most recent call last):\nFileNotFoundError: [Errno 2] No such file or directory\n";
    $checks = new FakeTaskCheckRunner([
        TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('c', 40), ['app'], $output, null, str_repeat('b', 40), 'check_error'),
    ]);
    app()->instance(TaskCheckRunner::class, $checks);
    app(TaskExtensionState::class)->enable();

    app(TaskScheduler::class)->tick();
    app(TaskScheduler::class)->tick();

    $check = TaskCheck::query()->sole();
    $reason = $group->fresh()?->assistance_reason;
    expect($checks->starts)->toBe(1)
        ->and($group->fresh()?->assistance_requested)->toBeTrue()
        ->and($reason)->toContain('The Project baseline check failed')
        ->and($reason)->not->toContain('workspace changed')
        ->and($check->status)->toBe(TaskCheckStatus::Failed)
        ->and($check->failed_step)->toBe('check_error')
        ->and($check->changed_paths)->toBe(['app'])
        ->and($check->output)->toBe($output);
});

it('retries GitHub setup rate limits with bounded backoff before asking for assistance', function (bool $recovers, bool $missingFinishedAt): void {
    $this->freezeTime();
    test_bind_snapshot_driver();
    app()->instance(AgentSpawner::class, new class implements AgentSpawner
    {
        public function spawnImplementer(Task $task): ?int
        {
            return test_agent_thread($task->parent, 'implementer', $task)->id;
        }

        public function reserveReviewer(Task $task): ?int
        {

            return null;

        }

        public function spawnReviewer(Task $task): ?int
        {
            return null;
        }

        public function requestReview(Task $task): void {}
    });
    $project = Project::query()->create(['name' => 'rate limits', 'slug' => 'rate-limits', 'repository_url' => 'https://github.com/acme/shop.git', 'task_check' => 'composer check']);
    $node = Node::query()->create(['name' => 'rate-limit-node', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '10.44.0.192', 'wireguard_ip' => '10.44.0.192']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'baseline-rate-limit', 'checkout_path' => '/tmp/baseline-rate-limit', 'starting_commit' => str_repeat('a', 40), 'status' => 'reserved']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Rate limits', 'brief' => 'Retry setup', 'status' => TaskGroupStatus::Running, 'execution_mode' => TaskExecutionMode::Managed]);
    $group->taskable()->associate($instance);
    $group->save();
    $task = Task::query()->create(['parent_id' => $group->id, 'position' => 1, 'title' => 'First task', 'brief' => 'Retry setup', 'status' => TaskStatus::Running]);
    $failure = TaskCheckReading::finished(1, str_repeat('a', 40), str_repeat('b', 40), [], 'The "https://codeload.github.com/phpstan/phpstan/legacy.zip/6155239b" file could not be downloaded (HTTP/2 429)', failedStep: 'install-apps-cli');
    $checks = new FakeTaskCheckRunner($recovers ? [$failure, FakeTaskCheckRunner::passed()] : array_fill(0, 5, $failure));
    app()->instance(TaskCheckRunner::class, $checks);
    app(TaskExtensionState::class)->enable();

    app(TaskScheduler::class)->tick();
    foreach ($recovers ? [60] : [60, 120, 240, 480] as $index => $seconds) {
        app(TaskScheduler::class)->tick();
        if ($missingFinishedAt) {
            TaskCheck::query()->latest('id')->firstOrFail()->update(['finished_at' => null]);
        }
        expect($group->fresh()->assistance_requested)->toBeFalse();
        expect($task->fresh()->implementer_agent_thread_id)->toBeNull();
        $this->travel($seconds - 1)->seconds();
        app(TaskScheduler::class)->tick();
        expect($checks->starts)->toBe($index + 1);
        $this->travel(1)->seconds();
        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();
        expect($checks->starts)->toBe($index + 2);
    }
    app(TaskScheduler::class)->tick();

    expect($group->fresh()->assistance_requested)->toBe(! $recovers, $group->fresh()->assistance_reason ?? '');
    expect($checks->starts)->toBe($recovers ? 2 : 5);
    expect(TaskCheck::query()->count())->toBe($recovers ? 2 : 5);
    if ($recovers) {
        expect(TaskCheck::query()->latest('id')->firstOrFail()->status)->toBe(TaskCheckStatus::Passed);
        expect($task->fresh()->implementer_agent_thread_id)->not->toBeNull();
    }
})->with(['recovers' => [true, false], 'exhausted' => [false, false], 'missing completion time' => [false, true]]);

it('classifies HTTP 429 and GitHub rate limits in failed setup steps as transient', function (?string $step, string $output, bool $retry): void {
    $check = new TaskCheck(['status' => TaskCheckStatus::Failed, 'failed_step' => $step, 'output' => $output]);

    expect(TaskSetupFailure::rateLimited($check))->toBe($retry);
})->with([
    'codeload' => ['install-apps-cli', 'https://codeload.github.com/phpstan/phpstan/legacy.zip/6155239b returned HTTP/2 429', true],
    'composer multiline' => ['install-apps-cli', "https://api.github.com/repos/phpstan/phpstan/zipball/6155239b\n  HTTP error 429", true],
    'api limit' => ['install', 'HTTP 403: API rate limit exceeded for 192.0.2.1', true],
    'secondary limit' => ['install', 'https://api.github.com: HTTP 403 secondary rate limit', true],
    'secondary limit body' => ['install', "https://api.github.com/repos/acme/shop: HTTP/2 403\nYou have exceeded a secondary rate limit.", true],
    'composer api limit' => ['install', 'GitHub API limit (60 calls/hr) is exhausted, could not fetch https://api.github.com/repos/acme/shop.', true],
    'forbidden' => ['install', 'https://api.github.com: HTTP 403 Forbidden', false],
    'credentials' => ['install', 'https://api.github.com: HTTP 401 Bad credentials', false],
    'other rate limit' => ['install', 'https://example.test: HTTP 429', true],
    'check failure' => [null, 'https://codeload.github.com: HTTP 429', false],
    'check error' => ['check_error', 'https://codeload.github.com: HTTP 429', false],
]);
