<?php

declare(strict_types=1);

use App\Actions\Doctor\Intent\RuntimeDoctorIntent;
use App\Actions\Instances\InstantiateProjectRuntimeDefinitionsAction;
use App\Actions\Instances\StartDevelopmentInstanceCreateAction;
use App\Actions\NodeFacts\AgentWatchListAction;
use App\Actions\Nodes\AllocateInstanceBridgeAction;
use App\Data\Instances\CreateInstanceData;
use App\Domain\AgentView\AgentNodeView;
use App\Domain\AgentView\AgentStateView;
use App\Domain\AgentView\AgentViewFreshness;
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
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\AgentChannelState;
use App\Infrastructure\Processes\ContainerProcessInspection;
use App\Infrastructure\Processes\RemoteProcessRuntimeManager;
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

    it('never sends a container Process to the Node agent, for a change or a live log stream', function (): void {
        expect(processContainerError(fn () => app(RemoteProcessRuntimeManager::class)->restart($this->process)))->toBe('process.target_unsupported')
            ->and(processContainerError(fn () => app(LogStreamTargetResolver::class)->forProcess($this->process)))->toBe('process.target_unsupported');
    });

    it('leaves the Node\'s idle hibernation and Doctor out of a container\'s Processes, which run in the container', function (): void {
        $node = Node::query()->findOrFail($this->instance->node_id);

        expect(app(DevelopmentHibernationPolicy::class)->appliesToProcess($this->process))->toBeFalse()
            ->and(app(RuntimeDoctorIntent::class)->execute($node)['processes'])->toBe([]);
    });

    it('reads a log through the container workflow with the unit, its container and the request', function (): void {
        $target = app(ProcessTargetResolver::class)->forInspection($this->process);
        $intent = ContainerProcessInspection::intent($this->process, $target, 'logs', 100, 'req');

        expect($intent['control'])->toBe(['process_id' => $this->process->id, 'name' => "orbit-process-{$this->process->id}-worker.service"])
            ->and($intent['container'])->toBe($target->container)
            ->and($intent['read'])->toBe(['action' => 'logs', 'lines' => 100, 'request' => 'req']);
    });

    it('lists container runtime status from the agent without starting an Operation', function (string $status): void {
        $state = new AgentChannelState;
        $state->apply('client-snapshot', ['sequence' => 1, 'part' => 1, 'parts' => 1, 'docker' => 'absent',
            'units' => [['runtime' => 'incus', 'name' => "orbit-process-{$this->process->id}-worker", 'runtime_status' => $status]]], microtime(true));
        $view = Mockery::mock(AgentStateView::class);
        $view->shouldReceive('node')->with($this->instance->node_id)->andReturn(new AgentNodeView(
            $this->instance->node_id, AgentViewFreshness::Fresh,
            $state->units,
        ));
        app()->instance(AgentStateView::class, $view);
        Queue::fake();
        $before = Operation::query()->count();

        expect(app(ProcessRuntimeStatusIndex::class)->statuses(collect([$this->process])))->toBe([$this->process->id => $status])
            ->and(app(RemoteProcessRuntimeManager::class)->status($this->process))->toBe($status)
            ->and(Operation::query()->count())->toBe($before);
        Queue::assertNothingPushed();
    })->with(['active', 'failed', 'inactive', 'unknown']);

    it('shows unknown without an explicit fresh container observation', function (bool $fresh, bool $hostReport): void {
        $units = $hostReport ? ["systemd:orbit-process-{$this->process->id}-worker" => 'active'] : [];
        $view = Mockery::mock(AgentStateView::class);
        $view->shouldReceive('node')->andReturn(new AgentNodeView(
            $this->instance->node_id, $fresh ? AgentViewFreshness::Fresh : AgentViewFreshness::Missing,
            $units,
        ));
        app()->instance(AgentStateView::class, $view);
        app(ProcessRuntimeStatusIndex::class)->remember($this->process, 'active');
        Queue::fake();

        expect(app(ProcessRuntimeStatusIndex::class)->statuses(collect([$this->process])))->toBe([$this->process->id => 'unknown']);
        Queue::assertNothingPushed();
    })->with([[true, false], [true, true], [false, false]]);

    it('lists container Process units in the Node watch list', function (): void {
        $node = Node::query()->findOrFail($this->instance->node_id);
        $watch = app(AgentWatchListAction::class)->execute($node);

        expect($watch['instance_containers'])->toBe([[
            'project' => 'orbit-instances', 'name' => 'instance-'.$this->instance->id,
            'units' => ["orbit-process-{$this->process->id}-worker.service"],
        ]]);
        expect($watch['services'])->not->toContain("orbit-process-{$this->process->id}-worker.service");
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

        expect($worker->refresh()->status)->toBe(LifecycleStatus::Provisioning)
            ->and($worker->runtime_config['command'])->toBe(['/usr/bin/env', 'node', 'worker.js', '--tries=3'])
            ->and(array_column($intent['processes'], 'name'))->toBe(["orbit-process-{$worker->id}-worker.service", 'orbit-process-'.($worker->id + 1).'-mailer.service'])
            ->and($intent['processes'][0]['environment'])->toBeNull();

        app(ContainerProcesses::class)->complete($instance, $intent['processes'], true, null);
        expect(Process::query()->where('owner_id', $instance->id)->pluck('status')->all())->toBe([LifecycleStatus::Active, LifecycleStatus::Active]);
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
