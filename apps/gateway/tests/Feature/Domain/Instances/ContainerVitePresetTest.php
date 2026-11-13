<?php

declare(strict_types=1);

use App\Actions\Instances\InstantiateProjectRuntimeDefinitionsAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Instances\ContainerProcesses;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Processes\VitePreset;
use App\Domain\Processes\VpDevPreset;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Process;
use App\Models\ProcessDefinition;
use App\Models\Project;
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

/** @return array<string, mixed> */
function vitePresetSpec(array $extra = []): array
{
    return ['runtime' => 'systemd', 'preset' => 'vite', 'preset_version' => 1, 'command' => VitePreset::definitionCommand(), ...$extra];
}

/** A container Instance with a private Route and one Process of the given runtime config. */
function vitePresetInstance(array $config, ?int $vitePort = null): Process
{
    $node = containerDefaultNode();
    $project = containerDefaultProject(ProjectType::LaravelApp, '8.5');
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'clone',
        'checkout_path' => '/fast/apps/tool/clone', 'branch' => 'main', 'vite_port' => $vitePort, 'status' => InstanceState::Active,
        'provisioning_step' => 'active', 'first_setup_pending' => false]);
    InstanceRuntime::query()->create(['instance_id' => $instance->id, 'node_id' => $node->id, 'incus_project' => 'orbit-instances',
        'container_name' => 'instance-'.$instance->id, 'bridge_address' => '10.253.0.2', 'runtime_version' => 'r', 'agent_version' => 'a',
        't3_port' => 3800, 'power_state' => InstanceRuntimePowerState::Running]);
    $route = Route::query()->create(['kind' => 'app', 'project_id' => $project->id, 'node_id' => $node->id, 'provenance' => 'explicit',
        'publication' => 'private', 'domain' => 'clone.tool.test', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);

    $config = ($config['preset'] ?? null) === 'vite' ? ['command' => VitePreset::command($instance), ...$config] : $config;

    return $instance->processes()->create(['name' => 'vite', 'runtime' => 'systemd', 'working_directory' => $instance->checkout_path,
        'runtime_config' => $config, 'restart_policy' => 'on-failure', 'desired_state' => 'running', 'status' => 'active']);
}

function vitePresetUnit(Process $process): string
{
    $instance = Instance::query()->findOrFail($process->owner_id);

    return app(ContainerProcesses::class)->unit($instance, $process)['content'];
}

/** The calling Node and a Project it serves through an Instance; the Project API needs that access edge. */
function vitePresetApi(): array
{
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default',
        'checkout_path' => '/home/orbit/apps/tool/default', 'status' => 'active', 'provisioning_step' => 'active']);
    $node->accessibleNodes()->attach($node->id);

    return [$node, $project];
}

function vitePresetRefusal(Closure $call): string
{
    try {
        $call();
    } catch (ResourceOperationException $exception) {
        return $exception->errorCode;
    }

    return 'admitted';
}

describe('a new vite Process definition', function (): void {
    it('is accepted for development, while a new vp-dev definition stays refused', function (): void {
        [$node, $project] = vitePresetApi();
        $api = $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

        $api->postJson("/api/v1/projects/{$project->id}/process-definitions", ['name' => 'vite', 'environments' => ['development'], 'spec' => vitePresetSpec()])
            ->assertCreated()->assertJsonPath('data.spec.preset', 'vite')->assertJsonPath('data.spec.command', VitePreset::definitionCommand());

        $refused = $api->postJson("/api/v1/projects/{$project->id}/process-definitions", ['name' => 'vp', 'environments' => ['development'],
            'spec' => ['runtime' => 'systemd', 'preset' => 'vp-dev', 'preset_version' => 1, 'command' => VpDevPreset::definitionCommand()]]);
        $refused->assertUnprocessable();
        expect($refused->json('error.details')['spec.preset'])
            ->toContain('Only an existing captured vp-dev definition with its canonical command and preset metadata can be edited.');
        expect(ProcessDefinition::query()->pluck('name')->all())->toBe(['vite']);
    });

    it('refuses a custom command, another environment or Docker', function (array $environments, array $spec): void {
        [$node, $project] = vitePresetApi();

        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
            ->postJson("/api/v1/projects/{$project->id}/process-definitions", ['name' => 'vite', 'environments' => $environments, 'spec' => $spec])
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        expect(ProcessDefinition::query()->count())->toBe(0);
    })->with([
        'custom command' => [['development'], vitePresetSpec(['command' => ['vite', '--port=5174']])],
        'production' => [['production'], vitePresetSpec()],
        'docker' => [['development'], vitePresetSpec(['runtime' => 'docker', 'image' => 'node:22'])],
    ]);
});

describe('capture of a vite definition', function (): void {
    it('records nothing on a container default, which has no development ports, while vp-dev stays as before', function (): void {
        $node = containerDefaultNode();
        $node->update(['tld' => 'beast.test']);
        Node::query()->where('name', 'control')->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']);
        $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('root-certificate');
        $project = containerDefaultProject(ProjectType::LaravelApp, '8.5');
        ProcessDefinition::query()->create(['project_id' => $project->id, 'name' => 'vite', 'environments' => ['development'], 'spec' => vitePresetSpec()]);

        $operation = containerCreate($project, $node);
        $instance = Instance::query()->findOrFail($operation->target_id);
        $container = containerIntentOf($operation);

        expect($instance->processes()->count())->toBe(0)
            ->and($container['processes'])->toBe([])
            ->and($container['units'][0]['content'])->not->toContain('INERTIA_SSR_HOT_URL');
    });

    it('still records a captured vp-dev definition on a container default', function (): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        ProcessDefinition::query()->create(['project_id' => $project->id, 'name' => 'dev', 'environments' => ['development'],
            'spec' => ['runtime' => 'systemd', 'preset' => 'vp-dev', 'preset_version' => 1, 'command' => VpDevPreset::definitionCommand()]]);

        $instance = Instance::query()->findOrFail(containerCreate($project, $node)->target_id);

        expect($instance->processes()->sole()->runtime_config['preset'])->toBe('vp-dev')
            ->and($instance->vite_port)->toBe(VpDevPreset::ContainerPort);
    });

    it('refuses a checkout target, which has no container to run it', function (): void {
        $node = containerDefaultNode(listed: false);
        $project = containerDefaultProject();
        ProcessDefinition::query()->create(['project_id' => $project->id, 'name' => 'vite', 'environments' => ['development'], 'spec' => vitePresetSpec()]);
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default',
            'checkout_path' => '/home/orbit/apps/tool/default', 'status' => 'active', 'provisioning_step' => 'active']);

        expect(vitePresetRefusal(fn () => app(InstantiateProjectRuntimeDefinitionsAction::class)->captureForDevelopment($instance)))
            ->toBe('process.container_command_unsupported')
            ->and(Process::query()->count())->toBe(0);
    });
});

describe('the vite unit', function (): void {
    it('runs the Project\'s own Vite with the Route URL and host allowance, and none of the vp-dev path settings', function (): void {
        $process = vitePresetInstance(['preset' => 'vite'], vitePort: 5195);
        $command = implode(' ', array_map(static fn (string $argument): string => '"'.$argument.'"', $process->runtime_config['command']));

        $unit = vitePresetUnit($process);

        expect($unit)->toContain("Environment=VITE_APP_URL=https://clone.tool.test\n", '"VITE_APP_URL=https://clone.tool.test"',
            "Environment=__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS=clone.tool.test\n",
            "UnsetEnvironment=VITE_DEV_SERVER_CERT VITE_DEV_SERVER_KEY\n",
            $command.'
')
            ->not->toContain('DEV_SERVER_ORIGIN=', 'ORBIT_DEV_SERVER_PORT', '--base', 'EnvironmentFile=-');
    });

    it('lets the Process\'s own VITE_APP_URL win, also when empty', function (string $value): void {
        $process = vitePresetInstance(['preset' => 'vite',
            'environment' => ['VITE_APP_URL' => $value]]);
        $target = app(ProcessTargetResolver::class)->forInspection($process);

        expect(vitePresetUnit($process))->not->toContain('VITE_APP_URL=https://clone.tool.test')
            ->and(app(SystemdProcessRenderer::class)->environmentContent($process, $target))->toContain('VITE_APP_URL="'.$value.'"');
    })->with(['empty' => '', 'own' => 'https://custom.tool.test']);

    it('gets no Vite upstream, no /__orbit/vite path and no assigned port', function (): void {
        $process = vitePresetInstance(['preset' => 'vite']);
        $instance = Instance::query()->findOrFail($process->owner_id);
        $site = app(DevelopmentSiteRepository::class)->workloadSite($instance, $instance->routes()->sole());

        expect(app(ContainerProcesses::class)->vitePort($instance))->toBeNull()
            ->and($site->viteUpstream)->toBeNull()
            ->and($site->devUpstream)->toBe('10.253.0.2')
            ->and(app(DevelopmentCaddyConfigRenderer::class)->render(collect([$site])))->not->toContain('@orbit_vite');
    });
});

describe('one dev-server preset per container', function (): void {
    it('refuses a second dev-server preset and another Process on the vite port', function (array $other): void {
        $process = vitePresetInstance(['preset' => 'vite'], vitePort: 5195);
        $instance = Instance::query()->findOrFail($process->owner_id);
        $instance->processes()->create(['name' => 'other', 'runtime' => 'systemd', 'working_directory' => '/srv/workspace',
            'runtime_config' => $other, 'restart_policy' => 'on-failure', 'desired_state' => 'running', 'status' => 'active']);

        expect(vitePresetRefusal(fn () => app(ContainerProcesses::class)->units($instance)))->toBe('process.container_port_conflict');
    })->with([
        'vp-dev' => [['preset' => 'vp-dev', 'command' => VpDevPreset::command(5195)]],
        'another vite' => [['preset' => 'vite', 'command' => ['/srv/workspace/node_modules/.bin/vite', '--host=0.0.0.0', '--port=5173', '--strictPort']]],
        'a Process on 5173' => [['command' => ['/usr/bin/env', 'node', 'server.js', '--port=5173']]],
    ]);
});
