<?php

declare(strict_types=1);

use App\Actions\Doctor\Intent\RuntimeDoctorIntent;
use App\Actions\Instances\InstantiateProjectRuntimeDefinitionsAction;
use App\Actions\Instances\StartDevelopmentInstanceCreateAction;
use App\Actions\Nodes\AllocateInstanceBridgeAction;
use App\Actions\Processes\DestroyProcessAction;
use App\Actions\Processes\ListProcessesAction;
use App\Data\Instances\CreateInstanceData;
use App\Domain\Hibernation\DevelopmentHibernationPolicy;
use App\Domain\Instances\ContainerNodes;
use App\Domain\Instances\ContainerProcesses;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Logs\LogStreamTargetResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessLifecycleInput;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Processes\ProcessRuntimeStatusIndex;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Processes\ProcessUsageIndex;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\RemoteProcessRuntimeManager;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\ProcessDefinition;
use App\Models\Project;
use App\Support\ValidatedData;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $swamp = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'control', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit',
    ]));
    $swamp->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
});

function processContainerNode(): Node
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'beast', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.7', 'public_ssh_host' => '10.44.0.7', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
        'settings' => ['apps' => ['path' => '/home/orbit/apps']],
    ]));
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    config(['instances.container_nodes' => [...app(ContainerNodes::class)->ids(), $node->id]]);
    app(AllocateInstanceBridgeAction::class)->execute($node);

    return $node->refresh();
}

function processContainerProject(): Project
{
    $project = Project::query()->create(['name' => 'tool', 'slug' => 'tool', 'type' => ProjectType::NodePackage, 'repository_url' => 'https://github.com/nckrtl/tool.git',
        'default_branch' => 'main', 'root' => '.', 'is_laravel' => false, 'source_facts_captured_at' => now()]);
    ProcessDefinition::query()->create(['project_id' => $project->id, 'name' => 'worker', 'environments' => ['development'],
        'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/env', 'node', 'worker.js'], 'environment' => ['QUEUE_TOKEN' => 'secret-value-41'],
            'restart_policy' => 'always']]);

    return $project;
}

/** The admitted create; the test has no Swamp, so its start fails after admission recorded everything. */
function processContainerCreate(Project $project, Node $node): Operation
{
    try {
        app(StartDevelopmentInstanceCreateAction::class)->execute(new CreateInstanceData($project->id, $node->id, 'default', null, null, null, null), null, null);
    } catch (ResourceOperationException $exception) {
        if ($exception->errorCode !== 'swamp.start_unavailable') {
            throw $exception;
        }
    }

    return Operation::query()->where('kind', 'instance.create')->latest('id')->firstOrFail();
}

/** @return list<array<string,mixed>> */
function processContainerUnits(Operation $operation): array
{
    return ValidatedData::object(ValidatedData::object($operation->input['direct_provisioning'])['container'])['processes'];
}

function processContainerError(Closure $call): string
{
    try {
        $call();
    } catch (ResourceOperationException $exception) {
        return $exception->errorCode;
    }

    return 'admitted';
}

describe('a container default with development Process definitions', function (): void {
    it('records each Process as an orbit unit in the container, its values kept out of the intent', function (): void {
        $operation = processContainerCreate(processContainerProject(), processContainerNode());
        $process = Process::query()->where('owner_id', $operation->target_id)->sole();
        $units = processContainerUnits($operation);
        $environment = '/etc/orbit/processes/'.$process->id.'/'.hash('sha256', "# Orbit Process {$process->id}\nQUEUE_TOKEN=\"secret-value-41\"\n").'.env';

        expect($process->status)->toBe(LifecycleStatus::Provisioning)
            ->and($process->working_directory)->toBe('/srv/workspace')
            ->and($units)->toHaveCount(1)
            ->and($units[0])->toMatchArray(['id' => $process->id, 'name' => "orbit-process-{$process->id}-worker.service",
                'environment' => $environment, 'desired' => 'running'])
            ->and($units[0]['content'])->toContain("User=orbit\nGroup=orbit\nUMask=0007\nWorkingDirectory=/srv/workspace\n")
            ->toContain("Environment=HOME=/home/orbit\nEnvironment=PATH=/opt/orbit-runtime/current/bin:/usr/local/bin:/usr/bin:/bin\n")
            ->toContain("EnvironmentFile=-/srv/workspace/.env\nEnvironmentFile={$environment}\n")
            ->and(str_contains(json_encode($operation->input, JSON_THROW_ON_ERROR), 'secret-value-41'))->toBeFalse()
            ->and($operation->input['members'])->toBe([]);
    });

    it('gives the run the environment values only as one vault copy, and only while the units are the admitted ones', function (): void {
        $operation = processContainerCreate(processContainerProject(), processContainerNode());
        $instance = Instance::query()->findOrFail($operation->target_id);
        $units = processContainerUnits($operation);

        expect(json_decode(app(ContainerProcesses::class)->environments($instance, $units), true))
            ->toBe([$units[0]['environment'] => "# Orbit Process {$units[0]['id']}\nQUEUE_TOKEN=\"secret-value-41\"\n"]);
        Process::query()->findOrFail($units[0]['id'])->forceFill(['runtime_config' => [...Process::query()->findOrFail($units[0]['id'])->runtime_config,
            'environment' => ['QUEUE_TOKEN' => 'changed']]])->save();
        expect(processContainerError(fn () => app(ContainerProcesses::class)->environments($instance, $units)))->toBe('operation.execution_forbidden');
    });

    it('refuses a Docker definition, which a container cannot run', function (): void {
        $project = processContainerProject();
        ProcessDefinition::query()->create(['project_id' => $project->id, 'name' => 'redis', 'environments' => ['development'],
            'spec' => ['runtime' => 'docker', 'image' => 'redis:7', 'command' => ['redis-server']]]);

        expect(processContainerError(fn () => processContainerCreate($project, processContainerNode())))->toBe('process.target_unsupported')
            ->and(Instance::query()->count())->toBe(0);
    });

    it('marks the Processes active when the create passes, and failed when it fails', function (string $status, LifecycleStatus $expected): void {
        $operation = processContainerCreate(processContainerProject(), processContainerNode());
        $operation->forceFill(['status' => 'running', 'finished_at' => null, 'error_code' => null, 'result' => null, 'state' => ['swamp_run_id' => 'run-1']])->save();
        $units = processContainerUnits($operation);

        app(DevelopmentInstanceCreateProjection::class)->apply($operation->refresh(), ['status' => $status, 'run_id' => 'run-1',
            'error_code' => $status === 'failed' ? 'instance.create_processes_failed' : null,
            'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
            'result' => ['ready' => $status === 'succeeded', 'instance_id' => $operation->target_id, 'commit' => str_repeat('d', 40), 'failed_step' => 'processes']]);

        $process = Process::query()->findOrFail($units[0]['id']);
        expect($process->status)->toBe($expected)
            ->and($process->systemd_activation_hash)->toBe($status === 'succeeded' ? hash('sha256', $units[0]['content']) : null);
    })->with([['succeeded', LifecycleStatus::Active], ['failed', LifecycleStatus::Failed]]);
});

describe('the Process target of a container Instance', function (): void {
    beforeEach(function (): void {
        $operation = processContainerCreate(processContainerProject(), processContainerNode());
        $this->instance = Instance::query()->findOrFail($operation->target_id);
        $this->instance->update(['status' => 'active', 'provisioning_step' => 'active', 'first_setup_pending' => false]);
        $this->process = Process::query()->where('owner_id', $this->instance->id)->sole();
        $this->process->update(['status' => LifecycleStatus::Active]);
    });

    it('names the container, so start, stop and restart act in it through Swamp', function (): void {
        $target = app(ProcessTargetResolver::class)->forInspection($this->process);
        $input = app(ProcessOperationAdmission::class)->inputFor($this->process, 'restart');
        $view = new Operation;
        $view->forceFill(['id' => 9, 'kind' => 'process.restart', 'target_type' => 'process', 'target_id' => $this->process->id,
            'node_id' => $this->instance->node_id, 'input' => $input]);
        $container = ['instance_id' => $this->instance->id, 'project' => 'orbit-instances', 'name' => 'instance-'.$this->instance->id];

        expect($target->user)->toBe('orbit')
            ->and($target->container)->toBe($container)
            ->and($input['container'])->toBe($container)
            ->and(app(ProcessLifecycleInput::class)->forOperation($view)['intent']['container'])->toBe($container);
    });

    it('refuses create, update and destroy: the Project\'s definitions and a deployment change them', function (string $verb): void {
        expect(processContainerError(fn () => app(ProcessOperationAdmission::class)->execute($this->process, $verb)))->toBe('operation.kind_unavailable')
            ->and(Operation::query()->where('target_type', 'process')->count())->toBe(0);
    })->with(['create', 'update', 'destroy']);

    it('names the commands that work when it refuses a Process its live definition owns', function (): void {
        $refusal = null;
        try {
            app(DestroyProcessAction::class)->admit($this->process, instanceId: $this->instance->id);
        } catch (ResourceOperationException $exception) {
            $refusal = $exception;
        }

        expect($refusal?->errorCode)->toBe('operation.kind_unavailable')
            ->and($refusal?->getMessage())->toContain("orbit process:destroy worker --project={$this->instance->project_id} --yes")
            ->toContain("orbit instance:deploy {$this->instance->id}")
            ->and(Operation::query()->where('target_type', 'process')->count())->toBe(0);
    });

    it('destroys a container Process that no current definition owns, only with its own Instance named', function (string $case): void {
        match ($case) {
            'removed definition' => ProcessDefinition::query()->whereKey($this->process->source_definition_id)->delete(),
            'no definition' => $this->process->update(['source_definition_id' => null]),
        };
        $other = Instance::query()->create(['node_id' => $this->instance->node_id, 'project_id' => $this->instance->project_id, 'name' => 'other',
            'checkout_path' => '/workspace/other', 'root' => '.', 'status' => 'active']);

        // A wrong or missing Instance stops before anything is recorded: a Process id of the default never removes it by mistake.
        expect(processContainerError(fn () => app(DestroyProcessAction::class)->admit($this->process->refresh())))->toBe('process.instance_mismatch')
            ->and(processContainerError(fn () => app(DestroyProcessAction::class)->admit($this->process->refresh(), instanceId: $other->id)))->toBe('process.instance_mismatch')
            ->and(Operation::query()->where('target_type', 'process')->count())->toBe(0)
            ->and($this->process->refresh()->status)->toBe(LifecycleStatus::Active);

        processContainerError(fn () => app(DestroyProcessAction::class)->admit($this->process->refresh(), instanceId: $this->instance->id));

        $operation = Operation::query()->where('kind', 'process.destroy')->sole();
        expect($operation->input['container'])->toBe(['instance_id' => $this->instance->id, 'project' => 'orbit-instances', 'name' => 'instance-'.$this->instance->id])
            ->and($operation->input['name'])->toBe(app(SystemdProcessRenderer::class)->unitName($this->process))
            ->and($this->process->refresh()->status)->toBe(LifecycleStatus::Removing);
    })->with(['removed definition', 'no definition']);

    it('reads the named Instance from the destroy request', function (): void {
        $this->process->update(['source_definition_id' => null]);
        $node = Node::query()->findOrFail($this->instance->node_id);
        $node->accessibleNodes()->syncWithoutDetaching([$node->id]);

        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
            ->deleteJson('/api/v1/processes/'.$this->process->id, ['instance' => $this->instance->id + 1000])
            ->assertStatus(422)->assertJsonPath('error.code', 'process.instance_mismatch');
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
            ->deleteJson('/api/v1/processes/'.$this->process->id, ['instance' => 'abc'])
            ->assertStatus(422);

        expect(Operation::query()->where('target_type', 'process')->count())->toBe(0);
    });

    it('never sends a container Process to the Node agent, for a change or a live log stream', function (): void {
        expect(processContainerError(fn () => app(RemoteProcessRuntimeManager::class)->restart($this->process)))->toBe('process.target_unsupported')
            ->and(processContainerError(fn () => app(LogStreamTargetResolver::class)->forProcess($this->process)))->toBe('process.target_unsupported');
    });

    it('leaves the Node\'s idle hibernation and Doctor out of a container\'s Processes, which run in the container', function (): void {
        $node = Node::query()->findOrFail($this->instance->node_id);

        expect(app(DevelopmentHibernationPolicy::class)->appliesToProcess($this->process))->toBeFalse()
            ->and(app(RuntimeDoctorIntent::class)->execute($node)['processes'])->toBe([]);
    });

    it('blocks container reads while migration runs or remains unfinished', function (string $status): void {
        Operation::query()->create(['kind' => 'instance.migrate', 'target_type' => 'instance', 'target_id' => $this->instance->id,
            'node_id' => $this->instance->node_id, 'status' => $status, 'input' => [], 'result' => ['moved' => false]]);
        Queue::fake();
        $ssh = Mockery::mock(SshExecutor::class);
        $ssh->shouldNotReceive('execute');
        app()->instance(SshExecutor::class, $ssh);
        $listed = listedContainerStatus($this->instance);
        expect($listed['runtime_status'])->toBe('unknown')
            ->and($listed['runtime_error_code'])->toBe('instance.lifecycle_busy')
            ->and($listed['observed_at'])->toBeNull();
        expect(processContainerError(fn () => app(RemoteProcessRuntimeManager::class)->logs($this->process, 100)))->toBe('instance.lifecycle_busy');
        Queue::assertNothingPushed();
    })->with(['queued', 'running', 'failed']);

    it('leaves a host Process status unchanged', function (): void {
        $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $this->instance->node_id,
            'name' => 'host-worker', 'runtime' => 'systemd', 'runtime_config' => ['command' => ['/usr/bin/true']],
            'status' => 'active', 'desired_state' => 'running']);
        app(ProcessRuntimeStatusIndex::class)->remember($process, 'active');
        Queue::fake();

        expect(app(ProcessRuntimeStatusIndex::class)->statuses(collect([$process])))->toBe([$process->id => 'active']);
        Queue::assertNothingPushed();
    });

});

describe('a deployment of a container default', function (): void {
    it('records new and changed definitions and admits their units', function (): void {
        $project = processContainerProject();
        $operation = processContainerCreate($project, processContainerNode());
        $instance = Instance::query()->findOrFail($operation->target_id);
        $worker = Process::query()->where('owner_id', $instance->id)->sole();
        $worker->update(['status' => LifecycleStatus::Active]);
        ProcessDefinition::query()->where('name', 'worker')->firstOrFail()->update(['spec' => ['runtime' => 'systemd',
            'command' => ['/usr/bin/env', 'node', 'worker.js', '--tries=3'], 'restart_policy' => 'always']]);
        ProcessDefinition::query()->create(['project_id' => $project->id, 'name' => 'mailer', 'environments' => ['development'],
            'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/env', 'node', 'mailer.js']]]);

        app(InstantiateProjectRuntimeDefinitionsAction::class)->refreshForContainer($instance);
        $intent = app(InstanceContainer::class)->intent($instance, $instance->runtime()->firstOrFail());

        // The worker runs its installed spec until the deployment installs the new one: it stays active, its activation pending.
        expect($worker->refresh()->status)->toBe(LifecycleStatus::Active)
            ->and($worker->systemd_activation_pending)->toBeTrue()
            ->and($worker->runtime_config['command'])->toBe(['/usr/bin/env', 'node', 'worker.js', '--tries=3'])
            ->and(array_column($intent['processes'], 'name'))->toBe(["orbit-process-{$worker->id}-worker.service", 'orbit-process-'.($worker->id + 1).'-mailer.service'])
            ->and($intent['processes'][0]['environment'])->toBeNull();

        app(ContainerProcesses::class)->complete($instance, $intent['processes'], true, null);
        expect(Process::query()->where('owner_id', $instance->id)->pluck('status')->all())->toBe([LifecycleStatus::Active, LifecycleStatus::Active])
            ->and($worker->refresh()->systemd_activation_pending)->toBeFalse();
    });

    it('keeps a changed Process that still runs active after a failed deployment, with the failed change beside it', function (): void {
        $project = processContainerProject();
        $operation = processContainerCreate($project, processContainerNode());
        $instance = Instance::query()->findOrFail($operation->target_id);
        $worker = Process::query()->where('owner_id', $instance->id)->sole();
        $installed = hash('sha256', processContainerUnits($operation)[0]['content']);
        $worker->forceFill(['status' => LifecycleStatus::Active, 'systemd_activation_hash' => $installed])->save();
        ProcessDefinition::query()->where('name', 'worker')->firstOrFail()->update(['spec' => ['runtime' => 'systemd',
            'command' => ['/usr/bin/env', 'node', 'worker.js', '--tries=3'], 'restart_policy' => 'always']]);
        ProcessDefinition::query()->create(['project_id' => $project->id, 'name' => 'mailer', 'environments' => ['development'],
            'spec' => ['runtime' => 'systemd', 'command' => ['/usr/bin/env', 'node', 'mailer.js']]]);
        app(InstantiateProjectRuntimeDefinitionsAction::class)->refreshForContainer($instance);
        $units = app(InstanceContainer::class)->intent($instance, $instance->runtime()->firstOrFail())['processes'];
        $mailer = Process::query()->where('owner_id', $instance->id)->where('name', 'mailer')->sole();

        // The run failed before it installed anything: the worker's old unit runs, the new mailer has none.
        app(ContainerProcesses::class)->complete($instance, $units, false, 'deployment.step_failed');
        expect($worker->refresh()->only(['status', 'systemd_activation_pending', 'systemd_activation_hash', 'failed_step', 'error_code']))
            ->toBe(['status' => LifecycleStatus::Active, 'systemd_activation_pending' => true, 'systemd_activation_hash' => $installed,
                'failed_step' => 'container', 'error_code' => 'deployment.step_failed'])
            ->and($mailer->refresh()->status)->toBe(LifecycleStatus::Failed);

        // A unit the run saw not become active is failed, even when its record was active.
        app(ContainerProcesses::class)->complete($instance, $units, false, 'instance.container_processes_failed', inactiveUnits: [$units[0]['name']]);
        expect($worker->refresh()->status)->toBe(LifecycleStatus::Failed);

        // The next passed deployment installs the new spec and clears the failure.
        app(ContainerProcesses::class)->complete($instance, $units, true, null);
        expect($worker->refresh()->only(['status', 'systemd_activation_pending', 'failed_step', 'error_code']))
            ->toBe(['status' => LifecycleStatus::Active, 'systemd_activation_pending' => false, 'failed_step' => null, 'error_code' => null])
            ->and($worker->systemd_activation_hash)->toBe(hash('sha256', $units[0]['content']));
    });

    it('leaves running Processes as they were when the deployment fails', function (): void {
        $operation = processContainerCreate(processContainerProject(), processContainerNode());
        $instance = Instance::query()->findOrFail($operation->target_id);
        Process::query()->where('owner_id', $instance->id)->update(['status' => LifecycleStatus::Active]);

        app(ContainerProcesses::class)->complete($instance, processContainerUnits($operation), false, 'deployment.processes_inactive');

        expect(Process::query()->where('owner_id', $instance->id)->sole()->status)->toBe(LifecycleStatus::Active);
    });
});

it('keeps a checkout Instance\'s Processes on the Node', function (): void {
    $node = processContainerNode();
    $project = processContainerProject();
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/home/orbit/apps/tool/default',
        'status' => 'active', 'provisioning_step' => 'active']);
    app(InstantiateProjectRuntimeDefinitionsAction::class)->captureForDevelopment($instance);
    $process = Process::query()->where('owner_id', $instance->id)->sole();

    expect(InstanceRuntime::query()->count())->toBe(0)
        ->and(app(DevelopmentHibernationPolicy::class)->appliesToProcess($process))->toBeTrue()
        ->and(array_column(app(RuntimeDoctorIntent::class)->execute($node)['processes'], 'id'))->toBe([$process->id])
        ->and(app(ProcessTargetResolver::class)->forInspection($process)->container)->toBeNull()
        ->and(app(ProcessOperationAdmission::class)->inputFor($process, 'restart'))->not->toHaveKey('container');
});

/** @return array<string,mixed> */
function listedContainerStatus(Instance $instance): array
{
    $usage = Mockery::mock(ProcessUsageIndex::class);
    $usage->shouldReceive('usage')->andReturn([]);
    app()->instance(ProcessUsageIndex::class, $usage);

    return app(ListProcessesAction::class)->execute(ProcessTargetType::Instance, $instance->id)->sole();
}
