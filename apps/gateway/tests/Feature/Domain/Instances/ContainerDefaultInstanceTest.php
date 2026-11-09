<?php

declare(strict_types=1);

use App\Actions\Instances\RenameInstanceAction;
use App\Actions\Instances\StartDevelopmentInstanceCreateAction;
use App\Actions\Instances\StartInstanceCloneAction;
use App\Actions\Instances\StartInstanceDestroyAction;
use App\Actions\Instances\StartInstanceEnvironmentImportAction;
use App\Actions\Instances\StartInstanceEnvironmentSyncAction;
use App\Actions\Instances\StartInstanceStepListAction;
use App\Actions\Instances\StartInstanceTransferAction;
use App\Actions\Nodes\AllocateInstanceBridgeAction;
use App\Data\Instances\CloneInstanceData;
use App\Data\Instances\CreateInstanceData;
use App\Data\Instances\RenameInstanceData;
use App\Data\Instances\TransferInstanceData;
use App\Domain\Instances\ContainerNodes;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceFolderPlan;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\ProcessDefinition;
use App\Models\Project;
use App\Models\ProjectDevelopmentDeployStep;
use App\Models\Route;
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

function containerDefaultNode(string $name = 'beast', string $ip = '10.44.0.7', bool $listed = true): Node
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => $ip, 'public_ssh_host' => $ip, 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
        'settings' => ['apps' => ['path' => '/home/orbit/apps']],
    ]));
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    if ($listed) {
        config(['instances.container_nodes' => [...app(ContainerNodes::class)->ids(), $node->id]]);
        app(AllocateInstanceBridgeAction::class)->execute($node);
    }

    return $node->refresh();
}

function containerDefaultProject(ProjectType $type = ProjectType::NodePackage, ?string $php = null): Project
{
    return Project::query()->create(['name' => 'tool', 'slug' => 'tool', 'type' => $type, 'repository_url' => 'https://github.com/nckrtl/tool.git',
        'default_branch' => 'main', 'root' => $type === ProjectType::LaravelApp ? 'public' : '.', 'php_version' => $php, 'is_laravel' => false, 'source_facts_captured_at' => now()]);
}

/** The admitted Operation; the test has no Swamp to start its run, so the start fails after admission recorded everything. */
function containerCreate(Project $project, Node $node, string $name = 'default', ?string $databaseServer = null): Operation
{
    $before = Operation::query()->max('id') ?? 0;
    try {
        app(StartDevelopmentInstanceCreateAction::class)->execute(
            new CreateInstanceData($project->id, $node->id, $name, null, null, null, $databaseServer), null, null);
    } catch (ResourceOperationException $exception) {
        if ($exception->errorCode !== 'swamp.start_unavailable') {
            throw $exception;
        }
    }

    return Operation::query()->where('id', '>', $before)->where('kind', 'instance.create')->latest('id')->firstOrFail();
}

/** @return array<string, mixed>|null */
function containerIntentOf(Operation $operation): ?array
{
    $container = ValidatedData::object($operation->input['direct_provisioning'])['container'];

    return $container === null ? null : ValidatedData::object($container);
}

function containerRefusal(Closure $create): string
{
    try {
        $create();
    } catch (ResourceOperationException $exception) {
        return $exception->errorCode;
    }

    return 'admitted';
}

describe('the first default Instance of a Project on a build-list Node', function (): void {
    it('is a container: its runtime takes the first guest address and the Node\'s folder versions, with no T3 port', function (): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();

        $operation = containerCreate($project, $node);

        $instance = Instance::query()->findOrFail($operation->target_id);
        $runtime = InstanceRuntime::query()->where('instance_id', $instance->id)->firstOrFail();
        $plan = InstanceFolderPlan::for(app(RecipeImageBuilds::class)->currentRecipe()->instances ?? throw new LogicException('The recipe has no Instance folders.'));
        expect($instance->name)->toBe('default')
            ->and($instance->checkout_path)->toBe("/var/lib/incus/storage-pools/orbit-instances/containers/orbit-instances_instance-{$instance->id}/rootfs/srv/workspace")
            ->and($runtime->only(['incus_project', 'container_name', 'bridge_address', 't3_port', 'runtime_version', 'agent_version', 'clone_source_instance_id', 'seed_commit']))
            ->toBe(['incus_project' => 'orbit-instances', 'container_name' => "instance-{$instance->id}", 'bridge_address' => '10.253.0.2', 't3_port' => null,
                'runtime_version' => $plan['runtime']['version'], 'agent_version' => $plan['agent']['version'], 'clone_source_instance_id' => null, 'seed_commit' => null])
            ->and($runtime->power_state)->toBe(InstanceRuntimePowerState::Stopped)
            ->and($instance->processes()->count())->toBe(0);
    });

    it('hands the run the container: its ACL, the mirror, the branch, the deploy steps and no setup steps', function (): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        ProjectDevelopmentDeployStep::query()->create(['project_id' => $project->id, 'name' => 'install', 'position' => 1, 'command' => 'bun install', 'required' => true, 'timeout_seconds' => 300]);

        $operation = containerCreate($project, $node);

        $container = containerIntentOf($operation);
        expect($container)->toMatchArray([
            'project' => 'orbit-instances', 'name' => 'instance-'.$operation->target_id, 'image' => 'orbit-instance', 'address' => '10.253.0.2',
            'network' => 'orbitinst0', 'acl' => 'instance-'.$operation->target_id,
            'acl_rules' => ['description' => 'Orbit Instance '.$operation->target_id, 'egress' => [],
                'ingress' => [['action' => 'allow', 'source' => '10.253.0.1/32', 'protocol' => 'tcp', 'destination_port' => '8080', 'state' => 'enabled']]],
            'mirror' => '/var/lib/orbit/instance-repositories/'.$operation->target_id.'.git', 'repository' => 'https://github.com/nckrtl/tool.git',
            'branch' => 'main', 'commit' => null, 'workspace' => '/srv/workspace', 'environment_file' => '/srv/workspace/.env', 'units' => [],
            'environment' => ['HOME=/home/orbit', 'USER=orbit', 'LOGNAME=orbit', 'PATH=/opt/orbit-runtime/current/bin:/usr/local/bin:/usr/bin:/bin', 'VP_NO_UPDATE_CHECK=1'],
            'steps' => [['name' => 'install', 'phase' => 'before_activation', 'required' => true, 'timeout_seconds' => 300, 'command_digest' => hash('sha256', 'bun install')]],
        ])->and($operation->input['run_budget_seconds'])->toBe(2100);
    });

    it('takes the next free address for a second Project\'s default on the same Node', function (): void {
        $node = containerDefaultNode();
        containerCreate(containerDefaultProject(), $node);
        $other = Project::query()->create(['name' => 'lib', 'slug' => 'lib', 'type' => ProjectType::NodePackage, 'repository_url' => 'https://github.com/nckrtl/lib.git',
            'default_branch' => 'main', 'root' => '.', 'source_facts_captured_at' => now()]);

        $operation = containerCreate($other, $node);

        expect(containerIntentOf($operation))->toMatchArray(['address' => '10.253.0.3']);
    });

    it('keeps its container on a repeat after a failure', function (): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        $first = containerCreate($project, $node);
        $first->forceFill(['status' => 'failed'])->save();

        $repeat = containerCreate($project, $node);

        expect(InstanceRuntime::query()->count())->toBe(1)
            ->and(containerIntentOf($repeat))->toMatchArray(['address' => '10.253.0.2']);
    });

    it('refuses a web-serving Project without a PHP version, which FrankenPHP could not serve', function (): void {
        $node = containerDefaultNode();

        expect(containerRefusal(fn () => containerCreate(containerDefaultProject(ProjectType::LaravelApp), $node)))->toBe('project.source_defaults_incomplete')
            ->and(Instance::query()->count())->toBe(0);
    });

    it('refuses a PHP Project until the runtime folder holds its static PHP build', function (): void {
        $node = containerDefaultNode();

        expect(containerRefusal(fn () => containerCreate(containerDefaultProject(ProjectType::LaravelApp, '8.5'), $node)))->toBe('instance.host_unavailable')
            ->and(Instance::query()->count())->toBe(0);
    });

    it('refuses what a container cannot run yet: a Database server and the Project\'s development Processes', function (): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();

        expect(containerRefusal(fn () => containerCreate($project, $node, databaseServer: 'beast-postgres')))->toBe('operation.kind_unavailable');
        ProcessDefinition::query()->create(['project_id' => $project->id, 'name' => 'queue', 'environments' => ['development'],
            'spec' => ['runtime' => 'systemd', 'command' => ['php', 'artisan', 'queue:work']]]);
        expect(containerRefusal(fn () => containerCreate($project, $node)))->toBe('operation.kind_unavailable')
            ->and(Instance::query()->count())->toBe(0);
    });
});

describe('other Instances of a Project with a container default', function (): void {
    it('are made on the default\'s Node only, and not before clones exist', function (): void {
        $beast = containerDefaultNode();
        $shark = containerDefaultNode('shark', '10.44.0.11', listed: false);
        $project = containerDefaultProject();
        containerCreate($project, $beast);

        expect(containerRefusal(fn () => containerCreate($project, $shark, 'feature')))->toBe('instance.default_missing')
            ->and(containerRefusal(fn () => containerCreate($project, $beast, 'feature')))->toBe('operation.kind_unavailable')
            ->and(Instance::query()->count())->toBe(1);
    });

    it('need a default first on a build-list Node', function (): void {
        $node = containerDefaultNode();

        expect(containerRefusal(fn () => containerCreate(containerDefaultProject(), $node, 'feature')))->toBe('instance.default_missing');
    });
});

it('leaves a Node that is not on the build list on checkouts', function (): void {
    $node = containerDefaultNode(listed: false);

    $operation = containerCreate(containerDefaultProject(), $node);

    expect(InstanceRuntime::query()->count())->toBe(0)
        ->and(containerIntentOf($operation))->toBeNull()
        ->and(Instance::query()->findOrFail($operation->target_id)->checkout_path)->toBe('/home/orbit/apps/tool/default');
});

it('renders FrankenPHP as an orbit unit on the bridge address, with the Project\'s PHP first on its path', function (): void {
    $node = containerDefaultNode();
    $project = containerDefaultProject(ProjectType::LaravelApp, '8.5');
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/x', 'root' => 'public']);
    $runtime = InstanceRuntime::query()->create(['instance_id' => $instance->id, 'node_id' => $node->id, 'incus_project' => 'orbit-instances',
        'container_name' => 'instance-'.$instance->id, 'bridge_address' => '10.253.0.2', 'runtime_version' => 'r', 'agent_version' => 'a',
        'power_state' => InstanceRuntimePowerState::Stopped]);

    $units = app(InstanceContainer::class)->units($instance, $runtime, '/srv/workspace');

    expect($units)->toHaveCount(1)
        ->and($units[0]['name'])->toBe('orbit-frankenphp.service')
        ->and($units[0]['content'])->toContain("User=orbit\nGroup=orbit\nUMask=0007\nWorkingDirectory=/srv/workspace\n")
        ->toContain('Environment=PATH=/opt/orbit-runtime/current/php/8.5/bin:/opt/orbit-runtime/current/bin:/usr/local/bin:/usr/bin:/bin')
        ->toContain('ExecStart=/opt/orbit-runtime/current/php/8.5/bin/frankenphp php-server --listen 10.253.0.2:8080 --root /srv/workspace/public')
        ->and(str_contains($units[0]['content'], '/etc/orbit/instance'))->toBeFalse();
});

it('serves a container Instance\'s Route by proxying to FrankenPHP at its bridge address, with no PHP pool on the Node', function (): void {
    $node = containerDefaultNode();
    $project = containerDefaultProject(ProjectType::LaravelApp, '8.5');
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/x']);
    InstanceRuntime::query()->create(['instance_id' => $instance->id, 'node_id' => $node->id, 'incus_project' => 'orbit-instances',
        'container_name' => 'instance-'.$instance->id, 'bridge_address' => '10.253.0.2', 'runtime_version' => 'r', 'agent_version' => 'a',
        'power_state' => InstanceRuntimePowerState::Running]);
    $route = Route::query()->create(['kind' => 'app', 'project_id' => $project->id, 'node_id' => $node->id, 'provenance' => 'explicit',
        'publication' => 'private', 'domain' => 'default.tool.test', 'status' => 'pending']);

    $site = app(DevelopmentSiteRepository::class)->workloadSite($instance, $route);
    $caddy = app(DevelopmentCaddyConfigRenderer::class)->render(collect([$site]));

    expect($site->localHttpUpstream)->toBe('10.253.0.2:8080')
        ->and($site->phpVersion)->toBeNull()
        ->and($site->checkoutPath)->toBe('')
        ->and($caddy)->toContain('reverse_proxy 10.253.0.2:8080')
        ->and(str_contains($caddy, 'php_fastcgi'))->toBeFalse();
});

it('records a created container default as running at its first seed commit', function (): void {
    $operation = containerCreate(containerDefaultProject(), containerDefaultNode());
    $operation->forceFill(['status' => 'running', 'finished_at' => null, 'error_code' => null, 'result' => null, 'state' => ['swamp_run_id' => 'run-1']])->save();
    $commit = str_repeat('d', 40);

    app(DevelopmentInstanceCreateProjection::class)->apply($operation->refresh(), ['status' => 'succeeded', 'run_id' => 'run-1',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['ready' => true, 'instance_id' => $operation->target_id, 'commit' => $commit]]);

    $runtime = InstanceRuntime::query()->where('instance_id', $operation->target_id)->firstOrFail();
    expect(Instance::query()->findOrFail($operation->target_id)->status)->toBe(InstanceState::Active)
        ->and($runtime->power_state)->toBe(InstanceRuntimePowerState::Running)
        ->and($runtime->seed_commit)->toBe($commit);
});

it('keeps a failed container default stopped, with the .env keys it wrote once its deploy steps ran', function (): void {
    $operation = containerCreate(containerDefaultProject(), containerDefaultNode());
    $operation->forceFill(['status' => 'running', 'finished_at' => null, 'error_code' => null, 'result' => null, 'state' => ['swamp_run_id' => 'run-1']])->save();

    app(DevelopmentInstanceCreateProjection::class)->apply($operation->refresh(), ['status' => 'failed', 'run_id' => 'run-1',
        'error_code' => 'instance.create_deploy_failed', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['ready' => false, 'instance_id' => $operation->target_id, 'commit' => str_repeat('e', 40), 'failed_step' => 'deploy']]);

    $instance = Instance::query()->findOrFail($operation->target_id);
    expect($instance->failed_step)->toBe('deploy')
        ->and($operation->input['environment_keys'])->not->toBeEmpty()
        ->and($instance->environment_owned_keys)->toBe($operation->input['environment_keys'])
        ->and(InstanceRuntime::query()->where('instance_id', $instance->id)->firstOrFail()->power_state)->toBe(InstanceRuntimePowerState::Stopped);
});

it('refuses every command that works on a checkout from the Node, for an Instance in a container', function (Closure $command): void {
    $operation = containerCreate(containerDefaultProject(), containerDefaultNode());
    $instance = Instance::query()->findOrFail($operation->target_id);
    $instance->update(['status' => InstanceState::Active, 'first_setup_pending' => false]);

    expect(containerRefusal(fn () => $command($instance)))->toBe('operation.kind_unavailable');
})->with([
    'destroy' => [fn (Instance $instance) => app(StartInstanceDestroyAction::class)->execute($instance, true)],
    'setup' => [fn (Instance $instance) => app(StartInstanceStepListAction::class)->execute($instance, LifecyclePhase::Setup, 'test', null)],
    'env:sync' => [fn (Instance $instance) => app(StartInstanceEnvironmentSyncAction::class)->execute($instance)],
    'env:import' => [fn (Instance $instance) => app(StartInstanceEnvironmentImportAction::class)->execute($instance, false)],
    'transfer' => [fn (Instance $instance) => app(StartInstanceTransferAction::class)->execute($instance, new TransferInstanceData(1, null, null))],
    'rename' => [fn (Instance $instance) => app(RenameInstanceAction::class)->execute($instance, new RenameInstanceData('feature'))],
    'clone' => [fn (Instance $instance) => app(StartInstanceCloneAction::class)->execute($instance, new CloneInstanceData(1, 'prod', 'prod', null, null))],
]);
