<?php

declare(strict_types=1);

use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Schedules\ScheduleErrorCode;
use App\Domain\Schedules\ScheduleOperationException;
use App\Domain\Schedules\ScheduleSpecification;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Schedules\RemoteScheduleRuntimeManager;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Schedule;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\Schedules\FakeScheduleRuntimeAccountResolver;

beforeEach(function (): void {
    $this->agents = new FakeAgentCommandRunner;
    foreach (['schedule.install', 'schedule.activate', 'schedule.run', 'schedule.remove'] as $command) {
        $this->agents->answer($command, []);
    }
    $this->agents->answer('schedule.logs', ['output' => "older\nnewest\n", 'truncated' => false]);
    $this->manager = new RemoteScheduleRuntimeManager(
        new ScheduleTargetResolver(new FakeScheduleRuntimeAccountResolver),
        new ScheduleSpecification,
        $this->agents,
    );
    $this->node = Node::query()->create([
        'name' => 'node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $this->schedule = Schedule::query()->create([
        'target_type' => Node::class,
        'target_id' => $this->node->id,
        'host_node_id' => $this->node->id,
        'name' => 'daily',
        'calendar' => 'daily',
        'command' => 'printf "sensitive command"',
        'timeout_seconds' => 3600,
        'desired_timer_state' => DesiredTimerState::Enabled,
        'status' => LifecycleStatus::Provisioning,
    ]);
});

it('requires the selected production release even when installing a disabled Schedule', function (): void {
    $node = Node::query()->create([
        'name' => 'app-prod',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.21',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.4',
    ]);
    $node->roles()->create(['role' => 'app-prod', 'status' => LifecycleStatus::Active]);
    $project = Project::query()->create([
        'name' => 'Release target',
        'slug' => 'release-target',
        'repository_url' => 'https://example.test/release-target.git',
    ]);
    $instance = $project->instances()->create([
        'node_id' => $node->id,
        'name' => 'main',
        'checkout_path' => '/home/managed/releases/prepared',
        'production_user' => 'managed',
        'production_home' => '/home/managed',
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
    $schedule = Schedule::query()->create([
        'target_type' => Instance::MorphAlias,
        'target_id' => $instance->id,
        'host_node_id' => $node->id,
        'name' => 'disabled-production',
        'calendar' => 'daily',
        'command' => 'true',
        'timeout_seconds' => 3600,
        'desired_timer_state' => DesiredTimerState::Disabled,
        'status' => LifecycleStatus::Provisioning,
    ]);
    $this->agents->refuse('schedule.install', 'schedule.target_unavailable');

    expect(fn () => $this->manager->install($schedule))
        ->toThrow(function (ScheduleOperationException $exception): void {
            expect($exception->error)->toBe(ScheduleErrorCode::TargetUnavailable);
        });

    expect($this->agents->calls)->toHaveCount(1)
        ->and($this->agents->calls[0]['input'])
        ->toMatchArray(['user' => 'managed', 'working_directory' => '/home/managed/current', 'desired' => 'disabled']);
});

it('sends only typed Schedule intent in one agent call', function (): void {
    $this->manager->install($this->schedule);

    $input = $this->agents->calls[0]['input'];
    expect($this->agents->commands())->toBe(['schedule.install'])
        ->and($input)->toMatchArray([
            'id' => $this->schedule->id,
            'user' => 'orbit',
            'group' => 'orbit',
            'desired' => 'enabled',
            'calendar' => 'daily',
            'command' => $this->schedule->command,
            'timeout_seconds' => 3600,
            'login_shell' => true,
        ])
        ->and(array_intersect(['script', 'service', 'timer'], array_keys($input)))->toBe([]);
});

it('maps each install refusal to its Schedule error', function (string $refusal, string $step, ScheduleErrorCode $error): void {
    $this->agents->refuse('schedule.install', $refusal);

    expect(fn () => $this->manager->install($this->schedule))
        ->toThrow(function (ScheduleOperationException $exception) use ($step, $error): void {
            expect($exception->step)->toBe($step)->and($exception->error)->toBe($error);
        });
})->with([
    ['schedule.artifact_conflict', 'inspect-artifacts', ScheduleErrorCode::ArtifactConflict],
    ['schedule.calendar_invalid', 'validate-calendar', ScheduleErrorCode::CalendarInvalid],
    ['schedule.rollback_failed', 'rollback', ScheduleErrorCode::RollbackFailed],
    ['schedule.failed', 'install', ScheduleErrorCode::InstallFailed],
]);

it('reports a Node that does not answer as unreachable', function (): void {
    $this->agents->answer('schedule.run', fn () => throw new RuntimeException('ssh failed'));

    expect(fn () => $this->manager->run($this->schedule))
        ->toThrow(fn (ScheduleOperationException $exception) => expect($exception->error)->toBe(ScheduleErrorCode::NodeUnreachable));
});

it('runs the Schedule and reads its bounded newest logs', function (): void {
    $this->manager->run($this->schedule);
    $logs = $this->manager->logs($this->schedule, 25);

    expect($this->agents->calls[1]['input'])->toBe(['id' => $this->schedule->id, 'lines' => 25, 'max_bytes' => 1_048_576])
        ->and($logs->output)->toBe("older\nnewest\n")
        ->and($logs->truncated)->toBeFalse();
});

it('retains standalone intent while a run is active, and removes on cascade', function (): void {
    $this->agents->refuse('schedule.remove', 'schedule.run_active');

    expect($this->manager->remove($this->schedule, false))->toBeFalse()
        ->and($this->agents->calls[0]['input'])->toBe(['id' => $this->schedule->id, 'group' => 'orbit', 'cascade' => false])
        ->and(fn () => $this->manager->remove($this->schedule, true))->toThrow(ScheduleOperationException::class);
});
