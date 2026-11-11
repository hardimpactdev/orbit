<?php

declare(strict_types=1);

use App\Actions\Instances\RenameInstanceAction;
use App\Actions\Instances\StartInstanceCloneAction;
use App\Actions\Instances\StartInstanceDestroyAction;
use App\Actions\Instances\StartInstanceEnvironmentImportAction;
use App\Actions\Instances\StartInstanceEnvironmentSyncAction;
use App\Actions\Instances\StartInstanceStepListAction;
use App\Actions\Instances\StartInstanceTransferAction;
use App\Actions\Projects\CaptureProjectSourceFactsAction;
use App\Actions\Projects\UpdateProjectAction;
use App\Data\Instances\CloneInstanceData;
use App\Data\Instances\RenameInstanceData;
use App\Data\Instances\TransferInstanceData;
use App\Data\Projects\UpdateProjectData;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceFolderPlan;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectSourceReader;
use App\Domain\Projects\ProjectType;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProjectDevelopmentDeployStep;
use App\Models\Route;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/ContainerInstanceHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $swamp = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'control', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit',
    ]));
    $swamp->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
});

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
            'root_disk_pool' => 'orbit-instances', 'root_disk_size' => '40GiB',
            'network' => 'orbitinst0', 'acl' => 'instance-'.$operation->target_id,
            'acl_rules' => ['description' => 'Orbit Instance '.$operation->target_id, 'egress' => [],
                'ingress' => [['action' => 'allow', 'source' => '10.253.0.1/32', 'protocol' => 'tcp', 'destination_port' => '8080', 'state' => 'enabled']]],
            'mirror' => '/var/lib/orbit/instance-repositories/'.$operation->target_id.'.git', 'repository' => 'https://github.com/nckrtl/tool.git',
            'branch' => 'main', 'commit' => null, 'workspace' => '/srv/workspace', 'environment_file' => '/srv/workspace/.env', 'units' => [],
            'processes' => [],
            'environment' => ['HOME=/home/orbit', 'USER=orbit', 'LOGNAME=orbit', 'PATH=/opt/orbit-runtime/current/bin:/usr/local/bin:/usr/bin:/bin',
                'VP_HOME=/var/lib/orbit/vite-plus-home', 'VP_NO_UPDATE_CHECK=1', 'HTTP_PROXY=http://10.253.0.1:3128',
                'HTTPS_PROXY=http://10.253.0.1:3128', 'NO_PROXY=localhost,127.0.0.1,::1,10.253.0.1', 'http_proxy=http://10.253.0.1:3128',
                'https_proxy=http://10.253.0.1:3128', 'no_proxy=localhost,127.0.0.1,::1,10.253.0.1', 'NODE_USE_ENV_PROXY=1'],
            'steps' => [['name' => 'install', 'phase' => 'before_activation', 'required' => true, 'timeout_seconds' => 300, 'command_digest' => hash('sha256', 'bun install')]],
            // A Project without a PHP version links no `php`.
            'php' => null,
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
        $first->forceFill(['status' => 'failed', 'lock_key' => null])->save();

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
        $recipe = json_decode((string) file_get_contents(resource_path('machine-software/recipe.json')), false, flags: JSON_THROW_ON_ERROR);
        $recipe->instances->php->versions->{'8.5'}->sha256 = null;
        $home = sys_get_temp_dir().'/unpublished-php';
        mkdir($home.'/release/apps/gateway/resources/machine-software', 0700, true);
        symlink('release', $home.'/orbit');
        file_put_contents($home.'/orbit/apps/gateway/resources/machine-software/recipe.json', json_encode($recipe, JSON_THROW_ON_ERROR));
        config(['orbit.gateway_checkout' => $home.'/orbit/apps/gateway']);

        expect(containerRefusal(fn () => containerCreate(containerDefaultProject(ProjectType::LaravelApp, '8.5'), $node)))->toBe('instance.host_unavailable')
            ->and(Instance::query()->count())->toBe(0);
    });
});

describe('other Instances of a Project with a container default', function (): void {
    it('are made on the default\'s Node only, and not before the default has its seed snapshot', function (): void {
        $beast = containerDefaultNode();
        $shark = containerDefaultNode('shark', '10.44.0.11', listed: false);
        $project = containerDefaultProject();
        containerCreate($project, $beast);

        expect(containerRefusal(fn () => containerCreate($project, $shark, 'feature')))->toBe('instance.default_missing')
            ->and(containerRefusal(fn () => containerCreate($project, $beast, 'feature')))->toBe('instance.default_missing')
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

it('links the Project\'s php for every PATH in the container, and no version the runtime folder does not hold', function (): void {
    $node = containerDefaultNode();
    $project = containerDefaultProject(ProjectType::LaravelApp, '8.5');
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default', 'checkout_path' => '/x', 'root' => 'public']);
    $runtime = InstanceRuntime::query()->create(['instance_id' => $instance->id, 'node_id' => $node->id, 'incus_project' => 'orbit-instances',
        'container_name' => 'instance-'.$instance->id, 'bridge_address' => '10.253.0.2', 'runtime_version' => 'r', 'agent_version' => 'a',
        'power_state' => InstanceRuntimePowerState::Stopped]);
    $node->forceFill(['instance_bridge' => '10.253.0.0/24'])->save();

    expect(app(InstanceContainer::class)->intent($instance, $runtime)['php'])->toBe('/opt/orbit-runtime/current/php/8.5/bin/php');

    $project->forceFill(['php_version' => '7.4'])->save();

    expect(app(InstanceContainer::class)->intent($instance->refresh(), $runtime)['php'])->toBeNull();
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

describe('monorepo source facts', function (): void {
    it('refreshes PHP from the root Composer requirement and hands the container its CLI link', function (): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject(ProjectType::Monorepo);
        $project->update(['root' => 'public']);
        $project->refresh();
        $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()
            ->with($project->repository_url, ProjectSourceAccess::GitHubApp, 'main', ['.env.example', 'composer.json', 'artisan'])
            ->andReturn(['head' => str_repeat('a', 40), 'files' => [
                '.env.example' => null, 'artisan' => null,
                'composer.json' => ['mode' => '100644', 'object' => str_repeat('b', 40), 'content' => '{"require":{"php":"^8.5"}}'],
            ]]);

        app(UpdateProjectAction::class)->execute($project, new UpdateProjectData(
            false, null, false, null, false, null, false, null, false, null,
            sourceAccessProvided: true, sourceAccess: ProjectSourceAccess::GitHubApp,
        ));

        expect($project->refresh())->php_version->toBe('8.5')->is_laravel->toBeFalse()->source_profile_error->toBeNull();
        expect(containerIntentOf(containerCreate($project, $node))['php'])->toBe('/opt/orbit-runtime/current/php/8.5/bin/php');
    });
});

it('keeps the source type rules when selecting PHP from Composer', function (ProjectType $type, ?string $json, bool $entry, ?string $php, bool $laravel, ?string $error): void {
    $entryPath = $type->frameworkEntryPoint();
    $this->mock(ProjectSourceReader::class)->shouldReceive('read')->once()->andReturn([
        'head' => str_repeat('a', 40), 'files' => [
            '.env.example' => null,
            'composer.json' => $json === null ? null : ['mode' => '100644', 'object' => str_repeat('b', 40), 'content' => $json],
            $entryPath => $entry ? ['mode' => '100644', 'object' => str_repeat('c', 40), 'content' => 'entry'] : null,
        ],
    ]);

    $read = app(CaptureProjectSourceFactsAction::class)->read('https://github.com/acme/source.git', ProjectSourceAccess::GitHubApp, 'main', '.', $type);

    expect($read['facts'])->toMatchArray(['php_version' => $php, 'is_laravel' => $laravel, 'source_profile_error' => $error]);
})->with([
    'monorepo uses root constraint without Laravel classification' => [ProjectType::Monorepo, '{"require":{"php":"~8.4.0","laravel/framework":"^13.0"}}', false, '8.4', false, null],
    'monorepo without Composer stays without PHP' => [ProjectType::Monorepo, null, false, null, false, null],
    'unsupported monorepo PHP is recorded' => [ProjectType::Monorepo, '{"require":{"php":"^7.4"}}', false, null, false, 'app-dev.php_version_unsupported'],
    'Laravel app' => [ProjectType::LaravelApp, '{"require":{"php":"^8.5","laravel/framework":"^13.0"}}', true, '8.5', true, null],
    'Symfony app' => [ProjectType::SymfonyApp, '{"require":{"php":"~8.4.0","symfony/framework-bundle":"^8.0"}}', true, '8.4', false, null],
    'Laravel package' => [ProjectType::LaravelPackage, '{"require":{"php":"^8.5","laravel/framework":"^13.0"}}', false, '8.5', false, null],
    'Node package without Composer' => [ProjectType::NodePackage, null, false, null, false, null],
]);
