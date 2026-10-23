<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Schedules\ScheduleErrorCode;
use App\Domain\Schedules\ScheduleOperationException;
use App\Domain\Schedules\ScheduleRenderer;
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
    foreach (['schedules.install', 'schedules.activate', 'schedules.run', 'schedules.remove'] as $command) {
        $this->agents->answer($command, []);
    }
    $this->agents->answer('schedules.logs', ['output' => "older\nnewest\n", 'truncated' => false]);
    $this->manager = new RemoteScheduleRuntimeManager(
        new ScheduleTargetResolver(new FakeScheduleRuntimeAccountResolver),
        new ScheduleRenderer(new ScheduleRuntimeFakeCertificates, 'https://10.44.0.1'),
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
    $this->agents->refuse('schedules.install', 'schedules.target_unavailable');

    expect(fn () => $this->manager->install($schedule))
        ->toThrow(function (ScheduleOperationException $exception): void {
            expect($exception->error)->toBe(ScheduleErrorCode::TargetUnavailable);
        });

    expect($this->agents->calls)->toHaveCount(1)
        ->and($this->agents->calls[0]['input'])
        ->toMatchArray(['user' => 'managed', 'working_directory' => '/home/managed/current', 'desired' => 'disabled']);
});

it('installs the rendered units, calendar, and desired timer state in one agent call', function (): void {
    $this->manager->install($this->schedule);

    $input = $this->agents->calls[0]['input'];
    expect($this->agents->commands())->toBe(['schedules.install'])
        ->and($input)->toMatchArray([
            'id' => $this->schedule->id,
            'user' => 'orbit',
            'group' => 'orbit',
            'desired' => 'enabled',
            'calendar' => 'daily',
        ])
        ->and($input['script'])->toContain('sensitive command')
        ->and($input['service'])->toContain('X-Orbit-Schedule-ID='.$this->schedule->id)
        ->and($input['timer'])->toContain('OnCalendar=daily');
});

it('maps each install refusal to its Schedule error', function (string $refusal, string $step, ScheduleErrorCode $error): void {
    $this->agents->refuse('schedules.install', $refusal);

    expect(fn () => $this->manager->install($this->schedule))
        ->toThrow(function (ScheduleOperationException $exception) use ($step, $error): void {
            expect($exception->step)->toBe($step)->and($exception->error)->toBe($error);
        });
})->with([
    ['schedules.artifact_conflict', 'inspect-artifacts', ScheduleErrorCode::ArtifactConflict],
    ['schedules.calendar_invalid', 'validate-calendar', ScheduleErrorCode::CalendarInvalid],
    ['schedules.rollback_failed', 'rollback', ScheduleErrorCode::RollbackFailed],
    ['schedules.failed', 'install', ScheduleErrorCode::InstallFailed],
]);

it('reports a Node that does not answer as unreachable', function (): void {
    $this->agents->answer('schedules.run', fn () => throw new RuntimeException('ssh failed'));

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
    $this->agents->refuse('schedules.remove', 'schedules.run_active');

    expect($this->manager->remove($this->schedule, false))->toBeFalse()
        ->and($this->agents->calls[0]['input'])->toBe(['id' => $this->schedule->id, 'group' => 'orbit', 'cascade' => false])
        ->and(fn () => $this->manager->remove($this->schedule, true))->toThrow(ScheduleOperationException::class);
});

it('maps root certificate rendering failures to the bounded install error', function (): void {
    $manager = new RemoteScheduleRuntimeManager(
        new ScheduleTargetResolver(new FakeScheduleRuntimeAccountResolver),
        new ScheduleRenderer(new ScheduleRuntimeFailingCertificates),
        $this->agents,
    );

    expect(fn () => $manager->install($this->schedule))
        ->toThrow(function (ScheduleOperationException $exception): void {
            expect($exception->step)->toBe('render')
                ->and($exception->error)->toBe(ScheduleErrorCode::InstallFailed)
                ->and($exception->getMessage())->toBe('Schedule installation failed.');
        })
        ->and($this->agents->calls)->toBe([]);
});

final class ScheduleRuntimeFakeCertificates implements LeafCertificateSigner
{
    public function sign(string $domain, string $certificateRequest): string
    {
        throw new LogicException('Signing is not part of Schedule runtime tests.');
    }

    public function rootCertificate(): string
    {
        return 'TEST ROOT CERTIFICATE';
    }
}

final class ScheduleRuntimeFailingCertificates implements LeafCertificateSigner
{
    public function sign(string $domain, string $certificateRequest): string
    {
        throw new LogicException('Signing is not part of Schedule runtime tests.');
    }

    public function rootCertificate(): string
    {
        throw new RuntimeException('Sensitive root certificate failure.');
    }
}
