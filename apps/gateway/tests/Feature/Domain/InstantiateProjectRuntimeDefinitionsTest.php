<?php

declare(strict_types=1);

use App\Actions\Instances\InstantiateProjectRuntimeDefinitionsAction;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Schedules\DesiredTimerState;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\ProcessDefinition;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\ScheduleDefinition;
use Tests\Support\Schedules\FakeScheduleRuntimeAccountResolver;

beforeEach(function (): void {
    $this->node = Node::query()->create([
        'name' => 'app-prod',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.30',
        'wireguard_ip' => '10.44.0.3',
        'user' => 'orbit',
    ]);
    $this->node->roles()->create(['role' => 'app-prod', 'status' => LifecycleStatus::Active]);
    $this->developmentNode = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.31',
        'wireguard_ip' => '10.44.0.4',
        'user' => 'orbit',
    ]);
    $this->developmentNode->roles()->create(['role' => 'app-dev', 'status' => LifecycleStatus::Active]);
    $this->orbitApp = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'git@example.test:acme.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $this->candidate = orb225_instance($this->orbitApp, $this->developmentNode, 'candidate', 'development');
    $this->target = orb225_instance($this->orbitApp, $this->node, 'target', 'production');
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldNotReceive('run');
    $runner->shouldNotReceive('install');
    app()->instance(AgentCommandRunner::class, $runner);
    $this->action = app(InstantiateProjectRuntimeDefinitionsAction::class);
});

it('selects App production definitions instead of development or candidate overrides', function (): void {
    $worker = orb225_process_definition($this->orbitApp, 'queue', ['production'], [
        'runtime' => 'systemd',
        'command' => ['/usr/bin/php', 'artisan', 'queue:work', '--tries=3'],
        'restart_policy' => 'always',
        'keep_alive' => true,
    ]);
    orb225_process_definition($this->orbitApp, 'vite', ['development'], [
        'runtime' => 'systemd',
        'command' => ['/usr/bin/vp', 'run', 'dev'],
    ]);
    $scheduleDefinition = orb225_schedule_definition($this->orbitApp, 'cleanup');
    $candidate = orb225_process($this->candidate, 'queue', ['/usr/bin/php', 'artisan', 'queue:work', '--tries=1']);

    $this->action->captureForClone($this->target);

    $copy = Process::query()
        ->whereIn('owner_type', Instance::morphTypes())
        ->where('owner_id', $this->target->id)
        ->sole();
    $schedule = Schedule::query()
        ->whereIn('target_type', Instance::morphTypes())
        ->where('target_id', $this->target->id)
        ->sole();

    expect($this->target->refresh()->runtime_definitions_captured_at)->not->toBeNull()
        ->and($copy->source_definition_id)->toBe($worker->id)
        ->and($copy->runtime_config['command'])->toBe(['/usr/bin/php', 'artisan', 'queue:work', '--tries=3'])
        ->and($copy->keep_alive)->toBeTrue()
        ->and($copy->desired_state)->toBe(DesiredProcessState::Stopped)
        ->and($copy->status)->toBe(LifecycleStatus::Provisioning)
        ->and($schedule->source_definition_id)->toBe($scheduleDefinition->id)
        ->and($schedule->host_node_id)->toBe($this->target->node_id)
        ->and($schedule->desired_timer_state)->toBe(DesiredTimerState::Disabled)
        ->and($schedule->status)->toBe(LifecycleStatus::Provisioning)
        ->and($candidate->refresh()->runtime_config['command'])
        ->toBe(['/usr/bin/php', 'artisan', 'queue:work', '--tries=1'])
        ->and(Process::query()->where('owner_id', $this->target->id)->where('name', 'vite')->exists())
        ->toBeFalse();
});

it('creates independent stopped copies for both Process backends and a disabled Schedule for a release-ready target', function (): void {
    $systemd = orb225_process_definition($this->orbitApp, 'queue', ['development', 'production'], [
        'runtime' => 'systemd',
        'command' => ['/usr/bin/php', 'artisan', 'queue:work'],
        'working_directory' => '/home/acme/shared',
        'restart_policy' => 'on-failure',
    ]);
    $docker = orb225_process_definition($this->orbitApp, 'redis', ['production'], [
        'runtime' => 'docker',
        'image' => 'redis:8-alpine',
        'command' => ['redis-server'],
        'environment' => ['ZEBRA' => 'last', 'ALPHA' => 'first'],
        'ports' => ['127.0.0.1:6380:6379/tcp'],
        'volumes' => [['source' => 'redis-data', 'target' => '/data']],
        'restart_policy' => 'unless-stopped',
    ]);
    $definition = orb225_schedule_definition($this->orbitApp, 'report');

    $this->action->captureForClone($this->target);

    $copies = Process::query()
        ->whereIn('owner_type', Instance::morphTypes())
        ->where('owner_id', $this->target->id)
        ->orderBy('name')
        ->get()
        ->keyBy('name');
    $schedule = Schedule::query()->where('target_id', $this->target->id)->sole();

    expect($copies)->toHaveCount(2)
        ->and($copies['queue']->id)->toBeInt()
        ->and($copies['queue']->source_definition_id)->toBe($systemd->id)
        ->and($copies['queue']->working_directory)->toBe('/home/acme/shared')
        ->and($copies['queue']->runtime_config)->toBe([
            'command' => ['/usr/bin/php', 'artisan', 'queue:work'],
            'environment_file' => '/home/acme-target/.env',
        ])
        ->and($copies['redis']->source_definition_id)->toBe($docker->id)
        ->and($copies['redis']->runtime_config)->toBe([
            'image' => 'redis:8-alpine',
            'command' => ['redis-server'],
            'environment' => ['ALPHA' => 'first', 'ZEBRA' => 'last'],
            'ports' => ['127.0.0.1:6380:6379/tcp'],
            'volumes' => [['source' => 'redis-data', 'target' => '/data', 'read_only' => false]],
        ])
        ->and($copies->pluck('desired_state')->unique()->all())->toBe([DesiredProcessState::Stopped])
        ->and($schedule->id)->not->toBe($definition->id)
        ->and($schedule->desired_timer_state)->toBe(DesiredTimerState::Disabled)
        ->and(Operation::query()->count())->toBe(0);
});

it('normalizes integer read_only values when instantiating stored process definitions', function (): void {
    orb225_process_definition($this->orbitApp, 'readonly', ['production'], [
        'runtime' => 'docker',
        'image' => 'redis:8-alpine',
        'command' => ['redis-server'],
        'volumes' => [['source' => 'readonly-data', 'target' => '/data', 'read_only' => 1]],
    ]);
    orb225_process_definition($this->orbitApp, 'writable', ['production'], [
        'runtime' => 'docker',
        'image' => 'redis:8-alpine',
        'command' => ['redis-server'],
        'volumes' => [['source' => 'writable-data', 'target' => '/data', 'read_only' => 0]],
    ]);

    $this->action->captureForClone($this->target);

    $copies = Process::query()->where('owner_id', $this->target->id)->get()->keyBy('name');

    expect($copies['readonly']->runtime_config['volumes'][0]['read_only'])->toBeTrue()
        ->and($copies['writable']->runtime_config['volumes'][0]['read_only'])->toBeFalse();
});

it('permits only captured copies to install before the production target becomes active', function (): void {
    $definition = orb225_process_definition($this->orbitApp, 'queue', ['production'], [
        'runtime' => 'systemd',
        'command' => ['/usr/bin/php', 'artisan', 'queue:work'],
    ]);
    $scheduleDefinition = orb225_schedule_definition($this->orbitApp, 'report');
    $this->action->captureForClone($this->target);
    $process = Process::query()->where('source_definition_id', $definition->id)->sole();
    $schedule = Schedule::query()->where('source_definition_id', $scheduleDefinition->id)->sole();
    $processTargets = new ProcessTargetResolver;
    $scheduleTargets = new ScheduleTargetResolver(new FakeScheduleRuntimeAccountResolver);

    expect(fn () => $processTargets->forAdmission($this->target->refresh()))
        ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'process.target_inactive')
        ->and(fn () => $processTargets->forProcess($process))
        ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'process.target_inactive')
        ->and($processTargets->forInstallation($process)->instance->id)->toBe($this->target->id)
        ->and(fn () => $scheduleTargets->forSchedule($schedule))
        ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'schedule.target_unavailable')
        ->and($scheduleTargets->forInstallation($schedule)->instance?->id)->toBe($this->target->id);
});

it('captures an empty production selection once', function (): void {
    $this->action->captureForClone($this->target);
    $capturedAt = $this->target->refresh()->runtime_definitions_captured_at;
    orb225_process_definition($this->orbitApp, 'late-worker', ['production'], [
        'runtime' => 'systemd',
        'command' => ['/usr/bin/php', 'artisan', 'queue:work'],
    ]);

    $this->action->captureForClone($this->target->refresh());

    expect($capturedAt)->not->toBeNull()
        ->and($this->target->refresh()->runtime_definitions_captured_at?->equalTo($capturedAt))->toBeTrue()
        ->and(Process::query()->where('owner_id', $this->target->id)->exists())->toBeFalse()
        ->and(Operation::query()->count())->toBe(0);
});

it('keeps the original capture after definitions change and never adopts conflicting records', function (): void {
    orb225_process_definition($this->orbitApp, 'queue', ['production'], ['runtime' => 'systemd', 'command' => ['/usr/bin/php', 'artisan', 'queue:work']]);
    $candidate = orb225_process($this->target, 'queue', ['/usr/bin/true']);
    expect(fn () => $this->action->captureForClone($this->target))->toThrow(ResourceOperationException::class);
    expect($candidate->refresh()->source_definition_id)->toBeNull();
    $candidate->delete();
    $this->action->captureForClone($this->target);
    $record = Process::query()->where('owner_id', $this->target->id)->sole();
    $record->update(['desired_state' => DesiredProcessState::Running, 'status' => LifecycleStatus::Failed]);
    orb225_process_definition($this->orbitApp, 'late', ['production'], ['runtime' => 'systemd', 'command' => ['/usr/bin/true']]);
    $this->action->captureForClone($this->target->refresh());
    expect(Process::query()->where('owner_id', $this->target->id)->count())->toBe(1)
        ->and($record->refresh()->desired_state)->toBe(DesiredProcessState::Running)
        ->and($record->status)->toBe(LifecycleStatus::Failed)
        ->and(Operation::query()->count())->toBe(0);
});

function orb225_instance(Project $project, Node $node, string $name, string $environment): Instance
{
    $production = $environment === 'production';

    return Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $name,
        'environment' => $environment,
        'source_layout' => 'checkout',
        'checkout_path' => $production ? "/home/acme-{$name}/releases/prepared" : "/srv/acme/{$name}",
        'production_user' => $production ? "acme-{$name}" : null,
        'production_home' => $production ? "/home/acme-{$name}" : null,
        'branch' => 'main',
        'starting_commit' => str_repeat('a', 40),
        'provisioning_step' => $production ? 'source-resolved' : 'active',
        'status' => $production ? 'source_resolved' : 'active',
    ]);
}

/** @param list<string> $command */
function orb225_process(Instance $instance, string $name, array $command): Process
{
    return Process::query()->create([
        'owner_type' => Instance::MorphAlias,
        'owner_id' => $instance->id,
        'name' => $name,
        'runtime' => ProcessRuntime::Systemd,
        'working_directory' => $instance->checkout_path,
        'runtime_config' => ['command' => $command],
        'restart_policy' => 'always',
        'desired_state' => DesiredProcessState::Running,
        'status' => LifecycleStatus::Active,
    ]);
}

/**
 * @param  list<string>  $environments
 * @param  array<string, mixed>  $specification
 */
function orb225_process_definition(
    Project $project,
    string $name,
    array $environments,
    array $specification,
): ProcessDefinition {
    return $project->processDefinitions()->create([
        'name' => $name,
        'environments' => $environments,
        'spec' => $specification,
    ]);
}

function orb225_schedule_definition(Project $project, string $name): ScheduleDefinition
{
    return $project->scheduleDefinitions()->create([
        'name' => $name,
        'environments' => ['production'],
        'spec' => [
            'command' => '/usr/bin/php artisan schedule:run',
            'calendar' => 'daily',
            'timeout_seconds' => 60,
        ],
    ]);
}

it('refuses record conflicts without adopting existing members', function (): void {
    $worker = orb225_process_definition($this->orbitApp, 'queue', ['production'], [
        'runtime' => 'systemd',
        'command' => ['/usr/bin/php', 'artisan', 'queue:work'],
    ]);
    $scheduleDefinition = orb225_schedule_definition($this->orbitApp, 'report');
    $candidate = orb225_process($this->candidate, 'candidate-worker', ['/usr/bin/php', 'artisan', 'queue:work']);
    $collision = orb225_process($this->target, 'queue', ['/usr/bin/php', 'artisan', 'queue:listen']);
    $scheduleCollision = orb225_schedule($this->target, 'report');

    expect(fn () => $this->action->capture($this->target->id))
        ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'process.name_taken');

    expect($this->target->refresh()->runtime_definitions_captured_at)->toBeNull()
        ->and($collision->refresh()->source_definition_id)->toBeNull()
        ->and($scheduleCollision->refresh()->source_definition_id)->toBeNull();

    $collision->delete();

    expect(fn () => $this->action->capture($this->target->id))
        ->toThrow(fn (ResourceOperationException $exception): bool => $exception->errorCode === 'schedule.retry_conflict');

    expect($this->target->refresh()->runtime_definitions_captured_at)->toBeNull()
        ->and(Process::query()->where('owner_id', $this->target->id)->exists())->toBeFalse()
        ->and($scheduleCollision->refresh()->source_definition_id)->toBeNull();

    $scheduleCollision->delete();
    $this->action->capture($this->target->id);
    expect(Process::query()->where('owner_id', $this->target->id)->sole()->source_definition_id)->toBe($worker->id)
        ->and(Schedule::query()->where('target_id', $this->target->id)->sole()->source_definition_id)->toBe($scheduleDefinition->id)
        ->and($candidate->fresh())->not->toBeNull();
});

function orb225_schedule(Instance $instance, string $name): Schedule
{
    return Schedule::query()->create([
        'target_type' => Instance::MorphAlias,
        'target_id' => $instance->id,
        'host_node_id' => $instance->node_id,
        'name' => $name,
        'calendar' => 'daily',
        'command' => '/usr/bin/php artisan schedule:run',
        'timeout_seconds' => 60,
        'desired_timer_state' => DesiredTimerState::Disabled,
        'status' => LifecycleStatus::Active,
    ]);
}
