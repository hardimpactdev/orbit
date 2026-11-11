<?php

declare(strict_types=1);

use App\Domain\Instances\ContainerProcesses;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Processes\VpDevPreset;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/ContainerInstanceHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
});

/** @return array{Instance, Process} */
function allowedHostsInstance(Node $node, Project $project, string $name, int $port, bool $container, bool $preset = true): array
{
    $checkout = '/fast/apps/tool/'.$name;
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => $name,
        'checkout_path' => $checkout, 'branch' => 'main', 'vite_port' => $port, 'status' => 'active',
        'provisioning_step' => 'active', 'source_is_laravel' => false]);
    if ($container) {
        InstanceRuntime::query()->create(['instance_id' => $instance->id, 'node_id' => $node->id, 'incus_project' => 'orbit-instances',
            'container_name' => 'instance-'.$instance->id, 'bridge_address' => '10.253.0.'.(1 + $instance->id),
            'runtime_version' => 'r', 'agent_version' => 'a', 'power_state' => 'running']);
    }
    allowedHostsRoute($instance, $name.'.tool.test');
    $process = $instance->processes()->create(['name' => 'vite', 'runtime' => 'systemd', 'working_directory' => $checkout,
        'runtime_config' => $preset ? ['preset' => VpDevPreset::NAME, 'command' => VpDevPreset::command($container ? $port : null)]
            : ['command' => ['/usr/bin/true']],
        'restart_policy' => 'on-failure', 'desired_state' => 'running', 'status' => 'active']);

    return [$instance, $process];
}

function allowedHostsRoute(Instance $instance, string $domain, ?string $webRoot = null): Route
{
    $route = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id, 'domain' => $domain,
        'web_root' => $webRoot, 'status' => 'pending', 'provenance' => 'explicit', 'publication' => 'private']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);

    return $route;
}

function allowedHostsUnit(Process $process): string
{
    return app(SystemdProcessRenderer::class)->render($process, app(ProcessTargetResolver::class)->forInspection($process));
}

describe('the vp-dev unit host allowance', function (): void {
    it('gives a clone its own hostname so stock laravel-vite-plugin host checks pass', function (bool $container): void {
        $node = containerDefaultNode();
        $project = containerDefaultProject();
        [, $default] = allowedHostsInstance($node, $project, 'default', 5195, $container);
        [$clone, $process] = allowedHostsInstance($node, $project, 'clone', 5173, $container);

        $unit = allowedHostsUnit($process);

        expect($unit)->toContain("Environment=__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS=clone.tool.test\n",
            '"__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS=clone.tool.test"');
        expect($unit)->not->toContain('default.tool.test', 'INERTIA_SSR_HOT_URL');
        expect(allowedHostsUnit($default))->toContain('Environment=__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS=default.tool.test');
        expect($clone->environmentValues()->count())->toBe(0);
    })->with(['host checkout' => false, 'container' => true]);

    it('lists the primary hostname first then every own Route in id order, using Vites comma separator', function (): void {
        [$instance, $process] = allowedHostsInstance(containerDefaultNode(), containerDefaultProject(), 'default', 5173, true);
        allowedHostsRoute($instance, 'docs.tool.test', 'docs/public');
        allowedHostsRoute($instance, 'admin.tool.test', 'admin/public');
        // Routes can arrive in any loaded order; the emitted list follows Route id and deduplicates the primary.
        $target = app(ProcessTargetResolver::class)->forInspection($process);
        $owner = $target->instance ?? throw new LogicException('Missing fixture Instance.');
        $owner->setRelation('routes', $owner->routes->reverse());

        DB::enableQueryLog();
        try {
            $unit = app(SystemdProcessRenderer::class)->render($process, $target);
            expect(DB::getQueryLog())->toBe([]);
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        expect($unit)->toContain("Environment=__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS=default.tool.test,docs.tool.test,admin.tool.test\n");
    });

    it('adds no allowance to a Process without the preset', function (): void {
        [, $process] = allowedHostsInstance(containerDefaultNode(), containerDefaultProject(), 'default', 5173, true, preset: false);

        expect(allowedHostsUnit($process))->not->toContain('__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS');
    });

    it('keeps the existing Process and Route read counts while building several container units', function (): void {
        [$instance] = allowedHostsInstance(containerDefaultNode(), containerDefaultProject(), 'default', 5173, true);
        allowedHostsRoute($instance, 'docs.tool.test', 'docs/public');
        foreach (['one', 'two', 'three'] as $name) {
            $instance->processes()->create(['name' => $name, 'runtime' => 'systemd', 'working_directory' => $instance->checkout_path,
                'runtime_config' => ['command' => ['/usr/bin/true']], 'status' => 'active']);
        }

        DB::enableQueryLog();
        try {
            $units = app(ContainerProcesses::class)->units($instance);
            $queries = DB::getQueryLog();
            expect($units)->toHaveCount(4);
            expect(array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'from "processes"')))->toHaveCount(1);
            // The existing resolver loads Routes once for each target; projecting hosts adds no reads.
            expect(array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'from "routes"')))->toHaveCount(4);
            expect($units[0]['content'])->toContain('Environment=__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS=default.tool.test,docs.tool.test');
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    });
});
