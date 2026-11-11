<?php

declare(strict_types=1);

use App\Domain\InstanceMigration\MigratedProcessDefinitions;
use App\Domain\Instances\ContainerProcesses;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Processes\VpDevPreset;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\ProcessDefinition;
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
        'provisioning_step' => 'active', 'first_setup_pending' => false, 'source_is_laravel' => false]);
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

    it('excludes unpublished and retiring Route hostnames', function (string $status): void {
        [$instance, $process] = allowedHostsInstance(containerDefaultNode(), containerDefaultProject(), 'default', 5173, true);
        $withdrawn = allowedHostsRoute($instance, 'withdrawn.tool.test', 'docs/public');
        $withdrawn->update(['status' => $status, 'sites_published' => false,
            'failed_step' => $status === 'failed' ? 'publish' : null, 'error_code' => $status === 'failed' ? 'route.failed' : null]);

        $unit = allowedHostsUnit($process);

        expect($unit)->toContain("Environment=__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS=default.tool.test\n");
        expect($unit)->not->toContain('withdrawn.tool.test');
    })->with(['pending', 'failed', 'retiring']);

    it('does not authorize an inactive resolver fallback when no authoritative Route remains', function (): void {
        [$instance, $process] = allowedHostsInstance(containerDefaultNode(), containerDefaultProject(), 'default', 5173, true);
        $instance->routes()->firstOrFail()->update(['status' => 'retiring', 'sites_published' => false]);

        expect(allowedHostsUnit($process))->not->toContain('__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS');
    });

    it('keeps the reserved primary hostname in a new clones first unit before Route publication', function (): void {
        [$instance, $process] = allowedHostsInstance(containerDefaultNode(), containerDefaultProject(), 'clone', 5173, true);
        $instance->routes()->firstOrFail()->update(['status' => 'pending', 'sites_published' => false]);
        $instance->update(['first_setup_pending' => true, 'status' => 'reserved', 'provisioning_step' => 'reserved']);

        expect(allowedHostsUnit($process))->toContain("Environment=__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS=clone.tool.test\n");
    });

    it('records a repeatable historical skip for actual pre-change vp-dev unit bytes without weakening the activation proof', function (bool $alreadyRedeployed, string $fixture): void {
        [$instance, $process] = allowedHostsInstance(containerDefaultNode(), containerDefaultProject(), 'default', 5195, true);
        $commit = str_repeat('d', 40);
        $instance->runtime()->firstOrFail()->update(['seed_commit' => $commit]);
        // Captured with the main renderers at 1720d2e6 and 9767ab2f; never synthesized from current units.
        $legacy = file_get_contents(base_path('tests/Fixtures/'.$fixture)) ?: throw new LogicException('Missing historical unit fixture.');
        $legacy = str_replace('X-Orbit-Process-ID=41', 'X-Orbit-Process-ID='.$process->id, $legacy);
        $unit = ['id' => $process->id, 'name' => app(SystemdProcessRenderer::class)->unitName($process),
            'content' => $legacy, 'environment' => null, 'desired' => 'running'];
        $operation = Operation::query()->create(['kind' => 'instance.migrate', 'target_type' => 'instance', 'target_id' => $instance->id,
            'node_id' => $instance->node_id, 'input' => ['container' => ['processes' => [$unit]]],
            'status' => 'succeeded', 'result' => ['instance_id' => $instance->id, 'moved' => true, 'restored' => false,
                'ready' => true, 'commit' => $commit]]);
        $activation = hash('sha256', $alreadyRedeployed ? app(ContainerProcesses::class)->unit($instance, $process, migrate: true)['content'] : $legacy);
        $process->forceFill(['systemd_activation_hash' => $activation])->save();

        app(MigratedProcessDefinitions::class)->repair();
        app(MigratedProcessDefinitions::class)->repair();

        expect($process->refresh()->source_definition_id)->toBeNull();
        expect($process->systemd_activation_hash)->toBe($activation);
        expect(ProcessDefinition::query()->count())->toBe(0);
        $finding = Activity::query()->where('command', 'instance:migrate:repair')->sole();
        expect($finding->description)->toBe('instance.migrate_process_definition_skipped');
        expect($finding->properties?->get('reason'))->toBe($alreadyRedeployed
            ? "Process [{$process->id}:{$process->name}] is not proven by the migrated default's activation."
            : "Process [{$process->id}:{$process->name}] current unit content differs from the admitted unit.");
        expect($operation->refresh()->input)->toBe(['container' => ['processes' => [$unit]]]);
    })->with(['original activation' => false, 'activated by a later deployment' => true])
        ->with(['vp-dev-before-allowed-hosts.service', 'vp-dev-before-native-dotenv.service']);

});

it('lets Vite load application dotenv and retains Orbit and private Process settings', function (bool $container): void {
    [, $process] = allowedHostsInstance(containerDefaultNode(), containerDefaultProject(), 'default', 5195, $container);
    $process->update(['runtime_config' => [...$process->runtime_config, 'environment_file' => '/srv/workspace/.env',
        'environment' => ['PRIVATE_SETTING' => 'synthetic-value']]]);

    $unit = allowedHostsUnit($process);
    expect($unit)->not->toContain('EnvironmentFile=-/srv/workspace/.env');
    expect($unit)->toContain('Environment=ORBIT_DEV_SERVER_ORIGIN=https://default.tool.test/__orbit/vite',
        'Environment=ORBIT_DEV_SERVER_HOST=default.tool.test', 'Environment=ORBIT_DEV_SERVER_PATH=/__orbit/vite',
        'Environment=ORBIT_DEV_SERVER_PORT=5195', 'EnvironmentFile=/etc/orbit/processes/'.$process->id.'/');
    expect($unit)->toContain('Environment=NODE_USE_SYSTEM_CA=1');
    if (! $container) {
        expect($unit)->toContain('EnvironmentFile='.SystemdProcessRenderer::viteEnvironmentPath($process->owner_id));
    }
})->with(['host checkout' => false, 'container' => true]);

it('changes only the application EnvironmentFile line of the admitted pre-change container unit', function (): void {
    [$instance, $process] = allowedHostsInstance(containerDefaultNode(), containerDefaultProject(), 'default', 5195, true);
    $legacy = file_get_contents(base_path('tests/Fixtures/vp-dev-before-native-dotenv.service'));
    $legacy = str_replace('X-Orbit-Process-ID=41', 'X-Orbit-Process-ID='.$process->id, $legacy);

    expect(app(ContainerProcesses::class)->unit($instance, $process, migrate: true)['content'])
        ->toBe(str_replace("EnvironmentFile=-/srv/workspace/.env\n", '', $legacy));
});

it('refuses pre-release unit admission before materializing migration execution inputs', function (): void {
    [$instance, $process] = allowedHostsInstance(containerDefaultNode(), containerDefaultProject(), 'default', 5195, true);
    $legacy = file_get_contents(base_path('tests/Fixtures/vp-dev-before-native-dotenv.service'));
    $legacy = str_replace('X-Orbit-Process-ID=41', 'X-Orbit-Process-ID='.$process->id, $legacy);
    $admitted = [['id' => $process->id, 'name' => app(SystemdProcessRenderer::class)->unitName($process),
        'content' => $legacy, 'environment' => null, 'desired' => 'running']];
    $process->forceFill(['systemd_activation_hash' => hash('sha256', $legacy)])->save();

    expect(fn () => app(ContainerProcesses::class)->environments($instance, $admitted, migrate: true))
        ->toThrow(ResourceOperationException::class, 'The container Processes changed after admission.');
    expect($process->refresh()->systemd_activation_hash)->toBe(hash('sha256', $legacy));
    expect($process->systemd_activation_pending)->toBeFalse();
});

it('records the admitted old unit activation when a submitted migration straddles the release', function (): void {
    [$instance, $process] = allowedHostsInstance(containerDefaultNode(), containerDefaultProject(), 'default', 5195, true);
    $legacy = file_get_contents(base_path('tests/Fixtures/vp-dev-before-native-dotenv.service'));
    $legacy = str_replace('X-Orbit-Process-ID=41', 'X-Orbit-Process-ID='.$process->id, $legacy);
    $admitted = [['id' => $process->id, 'name' => app(SystemdProcessRenderer::class)->unitName($process),
        'content' => $legacy, 'environment' => null, 'desired' => 'running']];

    app(ContainerProcesses::class)->complete($instance, $admitted, succeeded: true, errorCode: null, migrate: true);

    expect($process->refresh()->systemd_activation_hash)->toBe(hash('sha256', $legacy));
    expect($process->systemd_activation_pending)->toBeFalse();
});
