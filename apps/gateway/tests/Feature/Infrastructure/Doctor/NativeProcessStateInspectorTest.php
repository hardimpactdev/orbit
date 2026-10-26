<?php

declare(strict_types=1);

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\ProcessInspectionStatus;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentContract;
use App\Infrastructure\Doctor\NativeProcessStateInspector;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use Tests\Support\FakeAgentCommandRunner;

/** @return array{NativeProcessStateInspector, FakeAgentCommandRunner, Process} */
function native_process_inspector(ProcessRuntime $runtime, array $result, ?CommandDeadline $deadline = null, bool $malformed = false): array
{
    $node = Node::query()->create([
        'name' => fake()->unique()->word(),
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.51',
        'public_ssh_port' => 2022,
        'user' => 'nckrtl',
        'wireguard_ip' => '10.44.0.51',
    ]);
    orbit_test_set_app_placement_role($node, false);
    $project = Project::query()->create([
        'name' => fake()->word(),
        'slug' => fake()->unique()->slug(),
        'repository_url' => 'git@example.test:app.git',
    ]);
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => fake()->word(),
        'environment' => 'development',
        'checkout_path' => '/home/orbit/app',
        'source_is_laravel' => false,
        'provisioning_step' => 'active',
        'status' => 'active',
    ]);
    $process = Process::query()->create([
        'owner_type' => Instance::MorphAlias,
        'owner_id' => $instance->id,
        'name' => fake()->unique()->slug(2),
        'runtime' => $runtime,
        'working_directory' => '/tmp',
        'runtime_config' => ['opaque' => 'hidden'],
        'restart_policy' => 'always',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);
    $agents = $malformed
        ? new FakeAgentCommandRunner()->answerMalformed('doctor.inspect-process', $result)
        : new FakeAgentCommandRunner()->answer('doctor.inspect-process', $result);

    return [new NativeProcessStateInspector(new ProcessTargetResolver, $agents, new SystemdProcessRenderer, new DockerProcessRenderer, $deadline ?? new CommandDeadline, app(AgentContract::class)), $agents, $process];
}

/** @return array{present: bool, status: string, systemd_observations: list<array{active_state: string, sub_state: string, restarts: ?int}>} */
function native_process_observed(int $before = 0, int $after = 0): array
{
    return ['present' => true, 'status' => 'active', 'systemd_observations' => [
        ['active_state' => 'active', 'sub_state' => 'running', 'restarts' => $before],
        ['active_state' => 'active', 'sub_state' => 'running', 'restarts' => $after],
    ]];
}

it('sends Process intent and maps native restart evidence in one call', function (int $before, int $after, bool $crashLoop): void {
    [$inspector, $agents, $process] = native_process_inspector(ProcessRuntime::Systemd, native_process_observed($before, $after));

    $state = $inspector->inspect($process);

    expect($state->present)->toBeTrue()->and($state->status)->toBe(ProcessInspectionStatus::Active)
        ->and($state->isCrashLoop())->toBe($crashLoop);
    expect($agents->commands())->toBe(['doctor.inspect-process'])
        ->and($agents->calls[0]['input'])->toBe([
            'process_id' => $process->id, 'runtime' => 'systemd',
            'name' => "orbit-process-{$process->id}-{$process->name}.service", 'desired' => 'running',
        ]);
})->with([[0, 0, false], [26000, 26000, false], [3, 4, true], [26000, 0, false]]);

it('keeps automatic restart evidence and sends stopped intent unchanged', function (): void {
    [$inspector, $agents, $process] = native_process_inspector(ProcessRuntime::Systemd, [
        'present' => true, 'status' => 'other', 'systemd_observations' => [
            ['active_state' => 'activating', 'sub_state' => 'auto-restart', 'restarts' => 3],
        ],
    ]);
    $process->desired_state = DesiredProcessState::Stopped;

    expect($inspector->inspect($process)->isCrashLoop())->toBeTrue()
        ->and($agents->calls[0]['input']['desired'])->toBe('stopped');
});

it('maps absent and Docker runtime results', function (bool $present, ?string $status): void {
    [$inspector, $agents, $process] = native_process_inspector(ProcessRuntime::Docker, ['present' => $present, 'status' => $status, 'systemd_observations' => []]);

    $state = $inspector->inspect($process);

    expect($state->present)->toBe($present)->and($state->status?->value)->toBe($status)
        ->and($agents->calls[0]['input']['name'])->toBe("orbit-process-{$process->id}-{$process->name}");
})->with([[false, null], [true, 'running'], [true, 'created'], [true, 'exited'], [true, 'other']]);

it('caps the agent deadline without a second Gateway sampling timer', function (): void {
    $deadline = new CommandDeadline(static fn (): float => 100.0);
    $deadline->start(1.0);
    [$inspector, $agents, $process] = native_process_inspector(ProcessRuntime::Systemd, native_process_observed(), $deadline);

    $inspector->inspect($process);

    expect($agents->deadlines)->toBe([1.0]);
});

it('keeps the parent cleanup reserve after a bounded inspection failure', function (): void {
    $now = 100.0;
    $deadline = new CommandDeadline(static function () use (&$now): float {
        return $now;
    });
    $deadline->start(570.0, CommandDeadline::CleanupReserveSeconds);
    [$inspector, $agents, $process] = native_process_inspector(ProcessRuntime::Systemd, native_process_observed(), $deadline);
    $agents->answer('doctor.inspect-process', static function () use (&$now): never {
        $now += 10.0;
        throw new RuntimeException('private timeout details');
    });

    expect(fn () => $inspector->inspect($process))->toThrow(DoctorInspectionException::class)
        ->and($agents->deadlines)->toBe([10.0])->and($deadline->cap(9999.0))->toBe(540.0);
});

it('refuses an expired parent deadline before an agent call', function (): void {
    $now = 100.0;
    $deadline = new CommandDeadline(static function () use (&$now): float {
        return $now;
    });
    $deadline->start(1.0);
    [$inspector, $agents, $process] = native_process_inspector(ProcessRuntime::Systemd, native_process_observed(), $deadline);
    $now = 102.0;

    expect(fn () => $inspector->inspect($process))->toThrow(DoctorInspectionException::class)->and($agents->calls)->toBe([]);
});

it('reports malformed native observations as unavailable', function (array $result): void {
    [$inspector, , $process] = native_process_inspector(ProcessRuntime::Systemd, $result, malformed: true);

    expect(fn () => $inspector->inspect($process))->toThrow(DoctorInspectionException::class);
})->with([
    [[]],
    [['present' => true, 'status' => 'active', 'systemd_observations' => []]],
    [['present' => false, 'status' => 'active', 'systemd_observations' => []]],
    [['present' => true, 'status' => 'secret', 'systemd_observations' => [['active_state' => 'active', 'sub_state' => 'running', 'restarts' => 0]]]],
    [['present' => true, 'status' => 'active', 'systemd_observations' => [['active_state' => 'secret', 'sub_state' => 'running', 'restarts' => 0]]]],
    [['present' => true, 'status' => 'active', 'systemd_observations' => [['active_state' => 'active', 'sub_state' => 'running', 'restarts' => 'private']]]],
]);

it('keeps Process inspection behind the agent boundary', function (): void {
    foreach (['SshExecutor', 'RemoteCommand', 'ProcessRunner', 'Sleep::', '<<<'] as $forbidden) {
        expect(file_get_contents(app_path('Infrastructure/Doctor/NativeProcessStateInspector.php')))->not->toContain($forbidden);
    }
});
