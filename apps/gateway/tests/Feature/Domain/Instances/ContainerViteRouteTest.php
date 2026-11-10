<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Projects\ProjectType;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Process;
use App\Models\Route;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/ContainerInstanceHelpers.php';

it('routes a container preset and HMR path to its bridge port while keeping FrankenPHP as the application upstream', function (string $desired): void {
    $node = containerDefaultNode();
    $project = containerDefaultProject(ProjectType::LaravelApp, '8.5');
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default',
        'checkout_path' => '/fast/apps/tool/default', 'vite_port' => 5195, 'status' => InstanceState::Active]);
    InstanceRuntime::query()->create(['instance_id' => $instance->id, 'node_id' => $node->id, 'incus_project' => 'orbit-instances',
        'container_name' => 'instance-'.$instance->id, 'bridge_address' => '10.253.0.2', 'runtime_version' => 'r', 'agent_version' => 'a',
        'power_state' => InstanceRuntimePowerState::Running]);
    Process::query()->forceCreate(['owner_type' => 'instance', 'owner_id' => $instance->id, 'name' => 'vite', 'runtime' => 'systemd',
        'runtime_config' => ['preset' => 'vp-dev'], 'desired_state' => $desired, 'status' => 'active']);
    $route = Route::query()->create(['kind' => 'app', 'project_id' => $project->id, 'node_id' => $node->id, 'provenance' => 'explicit',
        'publication' => 'private', 'domain' => 'default.tool.test', 'status' => 'pending']);

    $site = app(DevelopmentSiteRepository::class)->workloadSite($instance, $route);
    $caddy = app(DevelopmentCaddyConfigRenderer::class)->render(collect([$site]));

    expect($site->viteUpstream)->toBe('10.253.0.2:5195');
    expect($caddy)->toContain('path /__orbit/vite /__orbit/vite/*')
        ->toContain("handle @orbit_vite {\n    reverse_proxy 10.253.0.2:5195\n}")
        ->toContain("handle {\n    reverse_proxy 10.253.0.2:8080 {")
        ->not->toContain('reverse_proxy 127.0.0.1:5195', 'handle_path', 'php_fastcgi');

    $instance->processes()->update(['status' => 'removing']);

    expect(app(DevelopmentSiteRepository::class)->workloadSite($instance, $route)->viteUpstream)->toBeNull();
})->with(['running', 'stopped']);

it('leaves a container with an assigned port and no preset on its application upstream', function (): void {
    $node = containerDefaultNode();
    $project = containerDefaultProject(ProjectType::LaravelApp, '8.5');
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default',
        'checkout_path' => '/fast/apps/tool/default', 'vite_port' => 5195, 'status' => InstanceState::Active]);
    InstanceRuntime::query()->create(['instance_id' => $instance->id, 'node_id' => $node->id, 'incus_project' => 'orbit-instances',
        'container_name' => 'instance-'.$instance->id, 'bridge_address' => '10.253.0.2', 'runtime_version' => 'r', 'agent_version' => 'a',
        'power_state' => InstanceRuntimePowerState::Running]);
    $route = Route::query()->create(['kind' => 'app', 'project_id' => $project->id, 'node_id' => $node->id, 'provenance' => 'explicit',
        'publication' => 'private', 'domain' => 'default.tool.test', 'status' => 'pending']);

    $site = app(DevelopmentSiteRepository::class)->workloadSite($instance, $route);
    $caddy = app(DevelopmentCaddyConfigRenderer::class)->render(collect([$site]));

    expect($site->viteUpstream)->toBeNull();
    expect($caddy)->toContain('reverse_proxy 10.253.0.2:8080')->not->toContain('@orbit_vite');
});

it('keeps a checkout development server on its loopback upstream', function (): void {
    $site = new DevelopmentSite(nodeId: 1, nodeAddress: '10.44.0.7', scope: 'app-instance-1',
        checkoutPath: '/fast/apps/tool/default', documentRoot: 'public', phpVersion: '8.5', domain: 'default.tool.test', vitePort: 5195);

    $caddy = app(DevelopmentCaddyConfigRenderer::class)->render(collect([$site]));

    expect($caddy)->toContain('path /__orbit/vite /__orbit/vite/*')
        ->toContain('reverse_proxy 127.0.0.1:5195')
        ->toContain('php_fastcgi unix//run/php/orbit-app-instance-1.sock');
});

it('batch-loads preset records for all container sites and ignores removing presets', function (): void {
    $node = containerDefaultNode();
    $project = containerDefaultProject(ProjectType::LaravelApp, '8.5');
    foreach ([1, 2] as $number) {
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'site-'.$number,
            'checkout_path' => '/fast/apps/tool/site-'.$number, 'vite_port' => 5194 + $number, 'status' => InstanceState::Active]);
        InstanceRuntime::query()->create(['instance_id' => $instance->id, 'node_id' => $node->id, 'incus_project' => 'orbit-instances',
            'container_name' => 'instance-'.$instance->id, 'bridge_address' => '10.253.0.'.($number + 1), 'runtime_version' => 'r', 'agent_version' => 'a',
            'power_state' => InstanceRuntimePowerState::Running]);
        foreach (['active', 'removing'] as $status) {
            Process::query()->forceCreate(['owner_type' => 'instance', 'owner_id' => $instance->id, 'name' => 'vite-'.$status, 'runtime' => 'systemd',
                'runtime_config' => ['preset' => 'vp-dev'], 'desired_state' => 'running', 'status' => $status]);
        }
        $route = Route::query()->create(['kind' => 'app', 'project_id' => $project->id, 'node_id' => $node->id, 'provenance' => 'explicit',
            'publication' => 'private', 'domain' => 'site-'.$number.'.tool.test', 'status' => 'pending']);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        $route->update(['status' => 'active', 'sites_published' => true]);
    }

    DB::enableQueryLog();
    try {
        $sites = app(DevelopmentSiteRepository::class)->renderAll();
        $reads = array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'from "processes"'));

        expect($sites->pluck('viteUpstream')->filter()->values()->all())->toBe(['10.253.0.2:5195', '10.253.0.3:5196']);
        expect($reads)->toHaveCount(1);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});
