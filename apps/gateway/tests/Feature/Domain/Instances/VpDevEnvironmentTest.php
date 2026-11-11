<?php

declare(strict_types=1);

use App\Domain\Instances\ContainerProcesses;
use App\Domain\Instances\Environment\DevelopmentCreateEnvironment;
use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentRenderer;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\Environment\NativeInstanceEnvironmentSync;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessTarget;
use App\Domain\Processes\VpDevPreset;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use App\Support\ValidatedData;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
});

/** @return ($preset is true ? array{Instance, Process} : array{Instance, null}) */
function vpEnvironmentInstance(string $name = 'default', int $port = 5195, bool $preset = true, bool $production = false): array
{
    $ip = '10.44.0.'.(10 + Node::query()->count());
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'node-'.$name, 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit',
        'public_ssh_host' => $ip, 'wireguard_ip' => $ip,
    ]));
    $node->roles()->create(['role' => $production ? RoleName::AppProd : RoleName::AppDev, 'status' => 'active']);
    $project = Project::query()->firstOrCreate(['slug' => 'vp-env'], [
        'name' => 'Vp environment', 'type' => 'laravel-app', 'root' => 'public', 'is_laravel' => true,
        'default_branch' => 'main', 'repository_url' => 'https://github.com/hardimpactdev/fixture.git',
    ]);
    $instance = Instance::query()->create([
        'node_id' => $node->id, 'project_id' => $project->id, 'name' => $name, 'branch' => 'main',
        'checkout_path' => '/srv/workspace', 'status' => 'active', 'provisioning_step' => 'active',
        'source_is_laravel' => true, 'vite_port' => $port,
    ]);
    vpEnvironmentRoute($instance, $name.'.example.test');
    $instance->environmentValues()->create(['env_key' => 'APP_URL', 'env_value' => 'https://{{instance.domain}}']);
    $process = $preset ? $instance->processes()->create([
        'name' => 'vite', 'runtime' => 'systemd', 'working_directory' => '/srv/workspace',
        'runtime_config' => ['preset' => VpDevPreset::NAME, 'command' => VpDevPreset::command($port)],
        'restart_policy' => 'on-failure', 'desired_state' => 'running', 'status' => 'active',
    ]) : null;

    return [$instance, $process];
}

function vpEnvironmentRoute(Instance $instance, string $domain, ?string $webRoot = null): Route
{
    $route = Route::query()->create([
        'project_id' => $instance->project_id, 'node_id' => $instance->node_id,
        'domain' => $domain, 'web_root' => $webRoot, 'status' => 'pending', 'provenance' => 'explicit', 'publication' => 'private',
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);

    return $route;
}

/** The same file renderer used by create, clone, deployment and synchronization. */
function vpEnvironmentContent(Instance $instance): string
{
    return app(DevelopmentCreateEnvironment::class)->content($instance);
}

function vpEnvironmentProcessContent(Instance $instance, Process $process): string
{
    return app(SystemdProcessRenderer::class)->environmentContent($process, new ProcessTarget(
        node: $instance->node, user: 'orbit', checkoutPath: '/srv/workspace',
        instance: $instance->refresh(), environmentFile: '/srv/workspace/.env', routeDomain: $instance->refresh()->authoritativeRoute()?->domain,
    ));
}

describe('Orbit-managed vp-dev environment', function (): void {
    it('uses a migrated defaults own non-default Vite port in the file and Process environment', function (): void {
        [$instance, $process] = vpEnvironmentInstance();
        $instance->node->forceFill(['instance_bridge' => '10.253.0.0/24'])->save();
        InstanceRuntime::query()->create(['instance_id' => $instance->id, 'node_id' => $instance->node_id,
            'incus_project' => 'orbit-instances', 'container_name' => 'instance-'.$instance->id,
            'bridge_address' => '10.253.0.2', 'runtime_version' => 'r', 'agent_version' => 'a', 'power_state' => 'running']);

        $units = app(ContainerProcesses::class)->units($instance);
        $processFiles = ValidatedData::object(json_decode(app(ContainerProcesses::class)->environments($instance, $units), true, flags: JSON_THROW_ON_ERROR));
        expect($units[0]['content'])->toContain('EnvironmentFile=-/srv/workspace/.env', 'EnvironmentFile='.$units[0]['environment']);

        foreach ([vpEnvironmentContent($instance), ValidatedData::string($processFiles[ValidatedData::string($units[0]['environment'])])] as $content) {
            expect($content)->toContain("INERTIA_SSR_HOT_URL=\"http://127.0.0.1:5195\"\n")
                ->toContain("__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS=\"default.example.test\"\n");
            expect($content)->not->toContain('INERTIA_SSR_URL=');
        }
    });

    it('gives a clone its own port and hostname so stock laravel-vite-plugin host checks pass', function (): void {
        [$default] = vpEnvironmentInstance();
        [$clone, $process] = vpEnvironmentInstance('clone', 5173);
        $create = app(DevelopmentCreateEnvironment::class);
        $create->admit($default, $default->project);
        app(InstanceEnvironmentStore::class)->seedForCreate($clone, app(InstanceEnvironmentStore::class)->values($default));
        $keys = $create->admit($clone, $clone->project, source: $default);

        foreach ([vpEnvironmentContent($clone), vpEnvironmentProcessContent($clone, $process)] as $content) {
            expect($content)->toContain('INERTIA_SSR_HOT_URL="http://127.0.0.1:5173"')
                ->toContain('__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS="clone.example.test"');
            expect($content)->not->toContain('default.example.test');
        }
        expect($keys)->toContain('INERTIA_SSR_HOT_URL', '__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS');
    });

    it('omits both keys without a vp-dev Process even when a plain Process is named vp-dev', function (): void {
        [$instance] = vpEnvironmentInstance(preset: false);
        $plain = $instance->processes()->create([
            'name' => 'vp-dev', 'runtime' => 'systemd', 'working_directory' => '/srv/workspace',
            'runtime_config' => ['command' => ['/usr/bin/true']], 'restart_policy' => 'always', 'status' => 'active',
        ]);

        foreach ([vpEnvironmentContent($instance), vpEnvironmentProcessContent($instance, $plain)] as $content) {
            expect($content)->not->toContain('INERTIA_SSR_HOT_URL', '__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS');
        }
    });

    it('replaces hand-set values in stored Instance and Process configuration and preserves the production SSR setting', function (): void {
        [$instance, $process] = vpEnvironmentInstance();
        foreach (['INERTIA_SSR_HOT_URL', '__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS'] as $key) {
            $instance->environmentValues()->create(['env_key' => $key, 'env_value' => 'hand-set']);
        }
        $instance->environmentValues()->create(['env_key' => 'INERTIA_SSR_URL', 'env_value' => 'http://production-renderer']);
        $process->update(['runtime_config' => [...$process->runtime_config, 'environment' => [
            'INERTIA_SSR_HOT_URL' => 'hand-set', '__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS' => 'hand-set',
        ]]]);

        foreach ([vpEnvironmentContent($instance), vpEnvironmentProcessContent($instance, $process)] as $content) {
            expect($content)->toContain('INERTIA_SSR_HOT_URL="http://127.0.0.1:5195"')
                ->toContain('__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS="default.example.test"');
            expect($content)->not->toContain('hand-set');
        }
        expect(vpEnvironmentContent($instance))->toContain('INERTIA_SSR_URL="http://production-renderer"');
    });

    it('uses the current port on every render for a host-checkout development Instance too', function (): void {
        [$instance, $process] = vpEnvironmentInstance();
        vpEnvironmentContent($instance);
        $instance->update(['vite_port' => 5201]);

        foreach ([vpEnvironmentContent($instance), vpEnvironmentProcessContent($instance, $process)] as $content) {
            expect($content)->toContain('INERTIA_SSR_HOT_URL="http://127.0.0.1:5201"');
            expect($content)->not->toContain(':5195');
        }
    });

    it('removes both keys on the next render after the preset is removed, including hand-set copies', function (): void {
        [$instance, $process] = vpEnvironmentInstance();
        app(DevelopmentCreateEnvironment::class)->admit($instance, $instance->project);
        $process->delete();

        expect(vpEnvironmentContent($instance))->not->toContain('INERTIA_SSR_HOT_URL', '__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS');
    });

    it('never renders either managed key on production Instances, even with a recorded preset and hand-set values', function (): void {
        [$instance, $process] = vpEnvironmentInstance(production: true);
        $values = ['INERTIA_SSR_HOT_URL' => 'hand-set', '__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS' => 'hand-set'];
        foreach ($values as $key => $value) {
            $instance->environmentValues()->create(['env_key' => $key, 'env_value' => $value]);
        }
        $process->update(['runtime_config' => [...$process->runtime_config, 'environment' => $values]]);
        $context = new InstanceEnvironmentContext(
            instanceId: $instance->id, projectId: $instance->project_id, nodeId: $instance->node_id,
            environment: 'production', path: '/srv/workspace', executionUser: 'orbit', laravel: true,
            routeId: null, routeDomain: 'default.example.test', nodeStatus: 'active', node: $instance->node,
        );

        foreach ([app(InstanceEnvironmentRenderer::class)->render($context, $values), vpEnvironmentProcessContent($instance, $process)] as $content) {
            expect($content)->not->toContain('INERTIA_SSR_HOT_URL', '__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS');
        }
    });

    it('allows every Route hostname through the shared Vite handler, primary first then Route id, and follows Route changes', function (): void {
        [$instance, $process] = vpEnvironmentInstance();
        $primary = $instance->routes()->whereNull('web_root')->firstOrFail();
        $docs = vpEnvironmentRoute($instance, 'docs.example.test', 'docs/public');
        vpEnvironmentRoute($instance, 'admin.example.test', 'admin/public');

        foreach ([vpEnvironmentContent($instance), vpEnvironmentProcessContent($instance, $process)] as $content) {
            expect($content)->toContain('__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS="default.example.test,docs.example.test,admin.example.test"');
        }
        $primary->update(['status' => 'retiring']);
        $primary->targets()->delete();
        $primary->delete();
        vpEnvironmentRoute($instance, 'renamed.example.test');
        $docs->update(['status' => 'retiring']);
        $docs->targets()->delete();
        $docs->delete();
        $context = app(InstanceEnvironmentContextResolver::class)->resolve($instance->refresh(), true);
        $values = app(InstanceEnvironmentStore::class)->values($instance);

        expect(app(InstanceEnvironmentRenderer::class)->render($context, $values))
            ->toContain('__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS="renamed.example.test,admin.example.test"');
        expect(vpEnvironmentProcessContent($instance, $process))
            ->toContain('__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS="renamed.example.test,admin.example.test"');
    });

    it('includes derived keys in the synchronization plan and owns their removal', function (): void {
        [$instance, $process] = vpEnvironmentInstance();
        $context = app(InstanceEnvironmentContextResolver::class)->resolve($instance, true);
        $sync = app(NativeInstanceEnvironmentSync::class);
        $plan = $sync->plan($context, includeRuntime: false);

        expect($plan['keys'])->toContain('INERTIA_SSR_HOT_URL', '__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS');
        $instance->forceFill(['environment_owned_keys' => $plan['keys']])->save();
        $process->delete();
        $after = $sync->plan($context, includeRuntime: false);
        expect($after['keys'])->not->toContain('INERTIA_SSR_HOT_URL', '__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS')
            ->and($after['owned_keys'])->toContain('INERTIA_SSR_HOT_URL', '__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS');
        expect($after['sha256'])->not->toBe($plan['sha256']);
    });
});
