<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\InstanceRuntimeDefinitions;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Schedules\ScheduleRuntimeAccountResolver;
use App\Domain\Schedules\ScheduleRuntimeManager;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\ProcessDefinition;
use App\Models\Project;
use App\Models\Schedule;
use App\Models\ScheduleDefinition;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRoutePublicationSocket;

function native_runtime_target(): Instance
{
    $node = Node::query()->create(['name' => 'runtime-target', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'public-runtime-pin', 'platform' => 'linux', 'status' => 'active',
        'user' => 'orbit', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '192.0.2.9']);
    $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Runtime', 'slug' => 'native-runtime',
        'repository_url' => 'https://example.test/runtime.git', 'default_branch' => 'main', 'root' => 'public']);

    return Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'production',
        'environment' => 'production', 'status' => 'active', 'provisioning_step' => 'active',
        'source_layout' => 'checkout', 'production_user' => 'orbit-app-'.$project->id,
        'production_home' => '/home/orbit-app-'.$project->id, 'checkout_path' => '/home/orbit-app-'.$project->id.'/releases/current',
        'branch' => 'main', 'root' => 'public', 'source_is_laravel' => false]);
}

function native_runtime_parent(Instance $instance, array $runtime, string $kind = 'instance.deploy'): Operation
{
    return new Operation(['kind' => $kind, 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $instance->node_id, 'status' => 'running',
        'input' => ['instance_id' => $instance->id, 'runtime_definitions' => $runtime]]);
}

function native_runtime_definitions(Instance $instance): void
{
    ProcessDefinition::query()->create(['project_id' => $instance->project_id, 'name' => 'queue', 'environments' => ['production'],
        'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/php', 'artisan', 'queue:work'], 'environment' => ['PASSWORD' => 'private-unit-value']]]);
    ProcessDefinition::query()->create(['project_id' => $instance->project_id, 'name' => 'cache', 'environments' => ['production'],
        'spec' => ['runtime' => 'docker', 'image' => 'redis:8-alpine', 'command' => ['redis-server'], 'environment' => ['PASSWORD' => 'private-container-value']]]);
    ScheduleDefinition::query()->create(['project_id' => $instance->project_id, 'name' => 'report', 'environments' => ['production'],
        'spec' => ['calendar' => 'daily', 'command' => 'printf private-schedule-value', 'timeout_seconds' => 60]]);
}

it('captures canonical production copies and private member material without runtime execution or child admission', function (): void {
    $this->mock(ProcessRuntimeManager::class)->shouldNotReceive('converge');
    $this->mock(ScheduleRuntimeManager::class)->shouldNotReceive('install');
    $this->mock(ScheduleRuntimeAccountResolver::class)->shouldNotReceive('resolve');
    $instance = native_runtime_target();
    native_runtime_definitions($instance);
    $runtime = app(InstanceRuntimeDefinitions::class)->capture($instance);
    $material = app(InstanceRuntimeDefinitions::class)->material(native_runtime_parent($instance, $runtime));

    expect($runtime['processes'])->toHaveCount(2)
        ->and($runtime['schedules'])->toHaveCount(1)
        ->and($runtime['restart'])->toBeEmpty()
        ->and($runtime['schedules'][0])->toMatchArray(['user' => $instance->production_user, 'home' => $instance->production_home,
            'working_directory' => $instance->production_home.'/current', 'shell' => '/bin/bash', 'login_shell' => false, 'desired' => 'disabled'])
        ->and(array_key_exists('group', $runtime['schedules'][0]))->toBeFalse()
        ->and(json_encode($runtime))->not->toContain('private-schedule-value')
        ->and(json_encode($runtime))->not->toContain('private-container-value')
        ->and(json_encode($runtime))->not->toContain('private-schedule-value')
        ->and(json_encode($material))->toContain('private-schedule-value')
        ->and(json_encode($material))->not->toContain('private-unit-value', 'private-container-value')
        ->and(Operation::query()->count())->toBe(0)
        ->and($instance->refresh()->runtime_definitions_captured_at)->not->toBeNull();
});

it('rolls definition capture back with the root admission transaction', function (): void {
    $instance = native_runtime_target();
    native_runtime_definitions($instance);

    expect(fn () => DB::transaction(function () use ($instance): void {
        app(InstanceRuntimeDefinitions::class)->capture($instance);
        throw new RuntimeException('root admission refused');
    }))->toThrow(RuntimeException::class, 'root admission refused');

    expect(Process::query()->count())->toBe(0)
        ->and(Schedule::query()->count())->toBe(0)
        ->and($instance->refresh()->runtime_definitions_captured_at)->toBeNull();
});

it('refuses private material for another parent Node or owner and changed member bytes', function (string $change): void {
    $instance = native_runtime_target();
    native_runtime_definitions($instance);
    $aggregate = app(InstanceRuntimeDefinitions::class);
    $runtime = $aggregate->capture($instance);
    $parent = native_runtime_parent($instance, $runtime);
    match ($change) {
        'node' => $parent->node_id++,
        'target' => $parent->target_id++,
        'kind' => $parent->kind = 'instance.destroy',
        'process-owner' => Process::query()->firstOrFail()->update(['owner_type' => Node::class, 'owner_id' => $instance->node_id]),
        'process-bytes' => Process::query()->where('runtime', 'systemd')->firstOrFail()->update(['runtime_config' => ['command' => ['/usr/bin/false']]]),
        'schedule-owner' => Schedule::query()->firstOrFail()->update(['target_type' => Node::class, 'target_id' => $instance->node_id]),
        'schedule-bytes' => Schedule::query()->firstOrFail()->update(['command' => 'printf changed']),
        'terminal' => $parent->status = 'succeeded',
    };

    expect(fn () => str_starts_with($change, 'process-') ? $aggregate->processWorkflowInput($parent) : $aggregate->material($parent))->toThrow(ResourceOperationException::class);
})->with(['node', 'target', 'kind', 'process-owner', 'process-bytes', 'schedule-owner', 'schedule-bytes', 'terminal']);

it('captures only running active restart-on-deploy members for rollback without copying definitions', function (): void {
    $instance = native_runtime_target();
    native_runtime_definitions($instance);
    $selected = Process::query()->create(['owner_type' => Instance::MorphAlias, 'owner_id' => $instance->id,
        'name' => 'existing', 'runtime' => 'systemd', 'working_directory' => $instance->production_home.'/current',
        'runtime_config' => ['command' => ['/usr/bin/true']], 'restart_policy' => 'never',
        'restart_on_deploy' => true, 'desired_state' => 'running', 'status' => 'active']);
    foreach (['stopped', 'failed', 'opted-out'] as $name) {
        $copy = $selected->replicate();
        $copy->name = $name;
        $copy->desired_state = $name === 'stopped' ? 'stopped' : 'running';
        $copy->status = $name === 'failed' ? 'failed' : 'active';
        $copy->restart_on_deploy = $name !== 'opted-out';
        $copy->save();
    }

    $runtime = app(InstanceRuntimeDefinitions::class)->restartSnapshot($instance);

    expect($runtime['processes'])->toBeEmpty()->and($runtime['schedules'])->toBeEmpty()
        ->and(array_column($runtime['restart'], 'process_id'))->toBe([$selected->id])
        ->and($runtime['restart'][0])->toHaveKeys(['process_id', 'runtime', 'name', 'on_demand', 'directory', 'vite'])
        ->and($runtime['restart'][0]['vite'])->toBeNull()
        ->and($instance->refresh()->runtime_definitions_captured_at)->toBeNull()
        ->and(Schedule::query()->count())->toBe(0);
});

it('projects owned member outcomes directly and leaves unreported members unchanged', function (): void {
    $instance = native_runtime_target();
    native_runtime_definitions($instance);
    $aggregate = app(InstanceRuntimeDefinitions::class);
    $runtime = $aggregate->capture($instance);
    $process = Process::query()->where('runtime', 'systemd')->sole();
    $other = Process::query()->where('runtime', 'docker')->sole();
    $schedule = Schedule::query()->sole();

    $aggregate->project(native_runtime_parent($instance, $runtime), [
        'processes' => [['process_id' => $process->id, 'ready' => true, 'state' => 'inactive']],
        'schedules' => [['id' => $schedule->id, 'ready' => false, 'error_code' => 'schedule.install_failed']],
    ]);

    expect($process->refresh()->status)->toBe(LifecycleStatus::Active)
        ->and($process->systemd_activation_hash)->not->toBeNull()
        ->and($schedule->refresh()->status)->toBe(LifecycleStatus::Failed)
        ->and($schedule->error_code)->toBe('schedule.install_failed')
        ->and($other->refresh()->status)->toBe(LifecycleStatus::Provisioning)
        ->and(Operation::query()->count())->toBe(0);
});

it('rejects projection for a member absent from admission without changing other members', function (): void {
    $instance = native_runtime_target();
    native_runtime_definitions($instance);
    $aggregate = app(InstanceRuntimeDefinitions::class);
    $runtime = $aggregate->capture($instance);
    $process = Process::query()->firstOrFail();
    $runtime['processes'] = array_values(array_filter($runtime['processes'], fn (array $entry): bool => $entry['process_id'] !== $process->id));

    expect(fn () => $aggregate->project(native_runtime_parent($instance, $runtime), [
        'processes' => [['process_id' => $process->id, 'ready' => true, 'state' => 'active']],
    ]))->toThrow(ResourceOperationException::class);
    expect($process->refresh()->status)->toBe(LifecycleStatus::Provisioning);
});

it('records native outcomes without re-rendering member configuration or requiring execution receipts', function (): void {
    $instance = native_runtime_target();
    native_runtime_definitions($instance);
    $aggregate = app(InstanceRuntimeDefinitions::class);
    $runtime = $aggregate->capture($instance);
    $process = Process::query()->where('runtime', 'systemd')->sole();
    $schedule = Schedule::query()->sole();
    $hash = array_values(array_filter($runtime['processes'], fn (array $entry): bool => $entry['process_id'] === $process->id))[0]['content_hash'];
    $process->update(['runtime_config' => ['command' => ['/usr/bin/false']]]);
    $schedule->update(['command' => 'printf changed-after-execution']);

    $aggregate->project(native_runtime_parent($instance, $runtime), [
        'processes' => [['process_id' => $process->id, 'ready' => true, 'state' => 'inactive']],
        'schedules' => [['id' => $schedule->id, 'ready' => true]],
    ]);

    expect($process->refresh()->status)->toBe(LifecycleStatus::Active)
        ->and($process->systemd_activation_hash)->toBe($hash)
        ->and($schedule->refresh()->status)->toBe(LifecycleStatus::Active)
        ->and(Operation::query()->count())->toBe(0);
});

it('accepts reordered public intent keys but preserves strict member identity types', function (): void {
    $instance = native_runtime_target();
    native_runtime_definitions($instance);
    $aggregate = app(InstanceRuntimeDefinitions::class);
    $runtime = $aggregate->capture($instance);
    $runtime['processes'] = array_map(fn (array $entry): array => array_reverse($entry, true), $runtime['processes']);
    $runtime['schedules'] = array_map(fn (array $entry): array => array_reverse($entry, true), $runtime['schedules']);

    expect(json_encode($aggregate->material(native_runtime_parent($instance, $runtime))))->toContain('private-schedule-value');
    $runtime['processes'][0]['on_demand'] = 0;
    expect(fn () => $aggregate->processWorkflowInput(native_runtime_parent($instance, $runtime)))->toThrow(ResourceOperationException::class);
});

it('publishes full deployment Process inputs with environment-only copies without publishing unit bytes as secrets', function (): void {
    $instance = native_runtime_target();
    native_runtime_definitions($instance);
    $runtime = app(InstanceRuntimeDefinitions::class)->capture($instance);
    $parent = native_runtime_parent($instance, $runtime);
    $parent->id = 321;
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => ['vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fixture']);
    $input = app(InstanceRuntimeDefinitions::class)->processWorkflowInput($parent);
    expect($input['members'])->toHaveCount(2)
        ->and(json_encode($input))->not->toContain('private-unit-value', 'private-container-value', 'private-schedule-value')
        ->and(array_values(array_filter($input['members'], fn (array $member): bool => $member['intent']['installation']['runtime'] === 'systemd'))[0]['intent']['installation']['unit'])->toContain('[Service]', 'X-Orbit-Process-ID=')
        ->and($socket->sent)->toHaveCount(2)
        ->and(json_encode(array_column(array_column($socket->sent, 'payload'), 'value')))->toContain('private-unit-value')
        ->and($socket->sent[0]['payload']['value'])->not->toContain('[Service]');
});
