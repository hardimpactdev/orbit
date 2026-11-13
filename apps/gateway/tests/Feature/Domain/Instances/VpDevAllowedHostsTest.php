<?php

declare(strict_types=1);

use App\Actions\Instances\InstantiateProjectRuntimeDefinitionsAction;
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
                'runtime_config' => ['command' => ['/usr/bin/true']], 'restart_policy' => 'never', 'desired_state' => 'stopped', 'status' => 'active']);
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

it('changes only the application EnvironmentFile line and adds the public origin alias of the admitted pre-change container unit', function (): void {
    [$instance, $process] = allowedHostsInstance(containerDefaultNode(), containerDefaultProject(), 'default', 5195, true);
    $legacy = file_get_contents(base_path('tests/Fixtures/vp-dev-before-native-dotenv.service'));
    $legacy = str_replace('X-Orbit-Process-ID=41', 'X-Orbit-Process-ID='.$process->id, $legacy);

    expect(app(ContainerProcesses::class)->unit($instance, $process, migrate: true)['content'])
        ->toBe(str_replace(
            ['EnvironmentFile=-/srv/workspace/.env'."\n", 'Environment=ORBIT_DEV_SERVER_ORIGIN=', '"ORBIT_DEV_SERVER_ORIGIN=https://default.tool.test/__orbit/vite"'],
            ['', 'Environment=DEV_SERVER_ORIGIN=https://default.tool.test/__orbit/vite'."\n".'Environment=ORBIT_DEV_SERVER_ORIGIN=',
                '"DEV_SERVER_ORIGIN=https://default.tool.test/__orbit/vite" "ORBIT_DEV_SERVER_ORIGIN=https://default.tool.test/__orbit/vite"'],
            $legacy,
        ));
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

it('adds only the generic public origin to an existing checkout dev-server unit', function (): void {
    [, $process] = allowedHostsInstance(containerDefaultNode(), containerDefaultProject(), 'checkout', 5195, false);
    // Captured on main at 718d7b891f; only the Process id is normalized.
    $baseline = <<<'UNIT'
[Unit]
Description=Orbit process vite
X-Orbit-Process-ID=__PROCESS_ID__
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=orbit
WorkingDirectory=/fast/apps/tool/checkout
Environment=PATH=/usr/local/bin:/opt/orbit/composer/vendor/bin:/usr/bin:/bin
Environment=NODE_USE_SYSTEM_CA=1
Environment=ORBIT_DEV_SERVER_ORIGIN=https://checkout.tool.test/__orbit/vite
Environment=ORBIT_DEV_SERVER_HOST=checkout.tool.test
Environment=ORBIT_DEV_SERVER_PATH=/__orbit/vite
Environment=__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS=checkout.tool.test
Environment=ORBIT_DEV_SERVER_PORT=5195
EnvironmentFile=/etc/orbit/vite/app-instance-1.env
UnsetEnvironment=VITE_DEV_SERVER_CERT VITE_DEV_SERVER_KEY
ExecStart="/usr/bin/env" "ORBIT_DEV_SERVER_ORIGIN=https://checkout.tool.test/__orbit/vite" "ORBIT_DEV_SERVER_HOST=checkout.tool.test" "ORBIT_DEV_SERVER_PATH=/__orbit/vite" "__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS=checkout.tool.test" "/usr/local/bin/vp" "dev" "--host=127.0.0.1" "--port=${ORBIT_DEV_SERVER_PORT}" "--strictPort" "--base=/__orbit/vite/"
Restart=on-failure
RestartSec=2

[Install]
WantedBy=multi-user.target
UNIT;
    $baseline = str_replace('__PROCESS_ID__', (string) $process->id, $baseline)."\n";
    $origin = 'https://checkout.tool.test/__orbit/vite';

    $unit = allowedHostsUnit($process);

    expect($unit)->toContain("Environment=DEV_SERVER_ORIGIN={$origin}\n", '"DEV_SERVER_ORIGIN='.$origin.'"',
        "Environment=ORBIT_DEV_SERVER_ORIGIN={$origin}\n", '"ORBIT_DEV_SERVER_ORIGIN='.$origin.'"');
    expect(str_replace(["Environment=DEV_SERVER_ORIGIN={$origin}\n", '"DEV_SERVER_ORIGIN='.$origin.'" '], '', $unit))
        ->toBe($baseline);
});

it('adds only the generic public origin to an existing container dev-server unit', function (): void {
    [, $process] = allowedHostsInstance(containerDefaultNode(), containerDefaultProject(), 'container', 5195, true);
    // Captured on main at 718d7b891f; only the Process id is normalized.
    $baseline = <<<'UNIT'
[Unit]
Description=Orbit process vite
X-Orbit-Process-ID=__PROCESS_ID__
After=network.target

[Service]
Type=simple
User=orbit
Group=orbit
UMask=0007
WorkingDirectory=/fast/apps/tool/container
Environment=HOME=/home/orbit
Environment=PATH=/opt/orbit-runtime/current/bin:/usr/local/bin:/usr/bin:/bin
Environment=NODE_USE_SYSTEM_CA=1
Environment=ORBIT_DEV_SERVER_ORIGIN=https://container.tool.test/__orbit/vite
Environment=ORBIT_DEV_SERVER_HOST=container.tool.test
Environment=ORBIT_DEV_SERVER_PATH=/__orbit/vite
Environment=__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS=container.tool.test
Environment=ORBIT_DEV_SERVER_PORT=5195
UnsetEnvironment=VITE_DEV_SERVER_CERT VITE_DEV_SERVER_KEY
ExecStart="/usr/bin/env" "ORBIT_DEV_SERVER_ORIGIN=https://container.tool.test/__orbit/vite" "ORBIT_DEV_SERVER_HOST=container.tool.test" "ORBIT_DEV_SERVER_PATH=/__orbit/vite" "__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS=container.tool.test" "ORBIT_DEV_SERVER_PORT=5195" "/opt/orbit-runtime/current/bin/vp" "dev" "--host=0.0.0.0" "--port=5195" "--strictPort" "--base=/__orbit/vite/"
Restart=on-failure
RestartSec=2

[Install]
WantedBy=multi-user.target
UNIT;
    $baseline = str_replace('__PROCESS_ID__', (string) $process->id, $baseline)."\n";
    $origin = 'https://container.tool.test/__orbit/vite';

    $unit = allowedHostsUnit($process);

    expect($unit)->toContain("Environment=DEV_SERVER_ORIGIN={$origin}\n", '"DEV_SERVER_ORIGIN='.$origin.'"',
        "Environment=ORBIT_DEV_SERVER_ORIGIN={$origin}\n", '"ORBIT_DEV_SERVER_ORIGIN='.$origin.'"');
    expect(str_replace(["Environment=DEV_SERVER_ORIGIN={$origin}\n", '"DEV_SERVER_ORIGIN='.$origin.'" '], '', $unit))
        ->toBe($baseline);
});

/** Synthetic definitions and owners used to compare exact renderer bytes with main. */
function originAliasCase(string $case): array
{
    $node = containerDefaultNode();
    $project = containerDefaultProject();
    $nodeCase = str_starts_with($case, 'node');
    $preset = str_starts_with($case, 'preset');
    $container = ! str_contains($case, 'checkout');
    if ($nodeCase) {
        $instance = null;
        $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id,
            'name' => 'worker', 'runtime' => 'systemd', 'working_directory' => '/home/orbit',
            'runtime_config' => ['command' => ['/usr/bin/true']], 'restart_policy' => 'never',
            'desired_state' => 'running', 'status' => 'active']);
    } else {
        [$instance, $process] = allowedHostsInstance($node, $project, 'default', 5195, $container, $preset);
        $process->update(['restart_on_deploy' => false]);
        if (str_contains($case, 'routeless')) {
            foreach ($instance->routes as $route) {
                $route->targets()->delete();
                $route->delete();
            }
        }
    }
    if (str_contains($case, 'supplied')) {
        $value = str_contains($case, 'empty') ? '' : 'https://project.example/own';
        $environment = ['DEV_SERVER_ORIGIN' => $value];
        if (! $nodeCase) {
            $environment += ['ORBIT_DEV_SERVER_ORIGIN' => 'https://ignored.example/orbit', 'PRIVATE_SETTING' => 'kept'];
        }
        if ($container && $instance !== null && ! str_contains($case, 'routeless')) {
            $definition = ProcessDefinition::query()->create(['project_id' => $project->id,
                'name' => 'dev', 'environments' => ['development'], 'spec' => [
                    'runtime' => 'systemd', 'command' => $preset ? VpDevPreset::definitionCommand() : ['/usr/bin/true'],
                    'environment' => $environment, 'restart_policy' => 'on-failure',
                    ...($preset ? ['preset' => VpDevPreset::NAME] : []),
                ]]);
            $target = app(ProcessTargetResolver::class)->forInspection($process);
            $attributes = app(InstantiateProjectRuntimeDefinitionsAction::class)
                ->attributesForDefinition($instance, $definition, $target);
            $process->forceFill($attributes)->save();
        } else {
            $process->update(['runtime_config' => [...$process->runtime_config, 'environment' => $environment]]);
        }
    }

    return [$process, app(ProcessTargetResolver::class)->forInspection($process)];
}

it('preserves main bytes for non-recipients and Project-supplied origins', function (string $case): void {
    [$process, $target] = originAliasCase($case);
    $renderer = app(SystemdProcessRenderer::class);
    $actual = ['unit' => $renderer->render($process, $target),
        'environment' => $renderer->environmentContent($process, $target),
        'path' => $renderer->environmentPath($process, $target)];
    $baseline = originAliasMainBytes($case);

    expect($actual['unit'])->toBe($baseline['unit']);
    expect($actual['environment'])->toBe($baseline['environment']);
    expect($actual['path'])->toBe($baseline['path']);
    expect($actual['unit'])->not->toContain('Environment=DEV_SERVER_ORIGIN=', '"DEV_SERVER_ORIGIN=');
    if (str_contains($case, 'supplied')) {
        $value = str_contains($case, 'empty') ? '' : 'https://project.example/own';
        expect($actual['environment'])->toContain('DEV_SERVER_ORIGIN="'.$value.'"');
        expect($process->runtime_config['environment']['DEV_SERVER_ORIGIN'])->toBe($value);
    }
})->with(['ordinary-route', 'node', 'routeless', 'preset-routeless',
    'preset-container-supplied', 'preset-checkout-supplied', 'preset-container-supplied-empty',
    'ordinary-route-supplied', 'node-supplied', 'routeless-supplied']);

/** Exact bytes captured from main at 8c0618a2bc; its renderer matches 718d7b891f. */
function originAliasMainBytes(string $case): array
{
    return json_decode(<<<'JSON'
{
    "ordinary-route": {
        "unit": "[Unit]\nDescription=Orbit process vite\nX-Orbit-Process-ID=1\nAfter=network.target\n\n[Service]\nType=simple\nUser=orbit\nGroup=orbit\nUMask=0007\nWorkingDirectory=\/fast\/apps\/tool\/default\nEnvironment=HOME=\/home\/orbit\nEnvironment=PATH=\/opt\/orbit-runtime\/current\/bin:\/usr\/local\/bin:\/usr\/bin:\/bin\nEnvironment=NODE_USE_SYSTEM_CA=1\nEnvironment=ORBIT_DEV_SERVER_ORIGIN=https:\/\/default.tool.test\/__orbit\/vite\nEnvironment=ORBIT_DEV_SERVER_HOST=default.tool.test\nEnvironment=ORBIT_DEV_SERVER_PATH=\/__orbit\/vite\nEnvironment=ORBIT_DEV_SERVER_PORT=5195\nExecStart=\"\/usr\/bin\/env\" \"ORBIT_DEV_SERVER_ORIGIN=https:\/\/default.tool.test\/__orbit\/vite\" \"ORBIT_DEV_SERVER_HOST=default.tool.test\" \"ORBIT_DEV_SERVER_PATH=\/__orbit\/vite\" \"ORBIT_DEV_SERVER_PORT=5195\" \"\/usr\/bin\/true\"\nRestart=on-failure\nRestartSec=2\n\n[Install]\nWantedBy=multi-user.target\n",
        "environment": "# Orbit Process 1\n",
        "path": "\/etc\/orbit\/processes\/1\/f4ae17981b6fb7d3db3d1466c15b057687796b364aaf0caa17d4e8fcd27c570c.env"
    },
    "node": {
        "unit": "[Unit]\nDescription=Orbit process worker\nX-Orbit-Process-ID=1\nAfter=network-online.target\nWants=network-online.target\n\n[Service]\nType=simple\nUser=orbit\nWorkingDirectory=\/home\/orbit\nEnvironment=PATH=\/usr\/local\/bin:\/opt\/orbit\/composer\/vendor\/bin:\/usr\/bin:\/bin\nEnvironment=NODE_USE_SYSTEM_CA=1\nExecStart=\"\/usr\/bin\/true\"\nRestart=no\nRestartSec=2\n\n[Install]\nWantedBy=multi-user.target\n",
        "environment": "# Orbit Process 1\n",
        "path": "\/etc\/orbit\/processes\/1\/f4ae17981b6fb7d3db3d1466c15b057687796b364aaf0caa17d4e8fcd27c570c.env"
    },
    "routeless": {
        "unit": "[Unit]\nDescription=Orbit process vite\nX-Orbit-Process-ID=1\nAfter=network.target\n\n[Service]\nType=simple\nUser=orbit\nGroup=orbit\nUMask=0007\nWorkingDirectory=\/fast\/apps\/tool\/default\nEnvironment=HOME=\/home\/orbit\nEnvironment=PATH=\/opt\/orbit-runtime\/current\/bin:\/usr\/local\/bin:\/usr\/bin:\/bin\nEnvironment=NODE_USE_SYSTEM_CA=1\nExecStart=\"\/usr\/bin\/true\"\nRestart=on-failure\nRestartSec=2\n\n[Install]\nWantedBy=multi-user.target\n",
        "environment": "# Orbit Process 1\n",
        "path": "\/etc\/orbit\/processes\/1\/f4ae17981b6fb7d3db3d1466c15b057687796b364aaf0caa17d4e8fcd27c570c.env"
    },
    "preset-routeless": {
        "unit": "[Unit]\nDescription=Orbit process vite\nX-Orbit-Process-ID=1\nAfter=network.target\n\n[Service]\nType=simple\nUser=orbit\nGroup=orbit\nUMask=0007\nWorkingDirectory=\/fast\/apps\/tool\/default\nEnvironment=HOME=\/home\/orbit\nEnvironment=PATH=\/opt\/orbit-runtime\/current\/bin:\/usr\/local\/bin:\/usr\/bin:\/bin\nEnvironment=NODE_USE_SYSTEM_CA=1\nEnvironment=ORBIT_DEV_SERVER_PORT=5195\nUnsetEnvironment=VITE_DEV_SERVER_CERT VITE_DEV_SERVER_KEY\nExecStart=\"\/usr\/bin\/env\" \"ORBIT_DEV_SERVER_PORT=5195\" \"\/opt\/orbit-runtime\/current\/bin\/vp\" \"dev\" \"--host=0.0.0.0\" \"--port=5195\" \"--strictPort\" \"--base=\/__orbit\/vite\/\"\nRestart=on-failure\nRestartSec=2\n\n[Install]\nWantedBy=multi-user.target\n",
        "environment": "# Orbit Process 1\n",
        "path": "\/etc\/orbit\/processes\/1\/f4ae17981b6fb7d3db3d1466c15b057687796b364aaf0caa17d4e8fcd27c570c.env"
    },
    "preset-container-supplied": {
        "unit": "[Unit]\nDescription=Orbit process vite\nX-Orbit-Process-ID=1\nAfter=network.target\n\n[Service]\nType=simple\nUser=orbit\nGroup=orbit\nUMask=0007\nWorkingDirectory=\/srv\/workspace\nEnvironment=HOME=\/home\/orbit\nEnvironment=PATH=\/opt\/orbit-runtime\/current\/bin:\/usr\/local\/bin:\/usr\/bin:\/bin\nEnvironment=NODE_USE_SYSTEM_CA=1\nEnvironmentFile=\/etc\/orbit\/processes\/1\/cf1d0dce8a189c27c2f022251522479e1d7808a812b9231f80a363be1979dca6.env\nEnvironment=ORBIT_DEV_SERVER_ORIGIN=https:\/\/default.tool.test\/__orbit\/vite\nEnvironment=ORBIT_DEV_SERVER_HOST=default.tool.test\nEnvironment=ORBIT_DEV_SERVER_PATH=\/__orbit\/vite\nEnvironment=__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS=default.tool.test\nEnvironment=ORBIT_DEV_SERVER_PORT=5195\nUnsetEnvironment=VITE_DEV_SERVER_CERT VITE_DEV_SERVER_KEY\nExecStart=\"\/usr\/bin\/env\" \"ORBIT_DEV_SERVER_ORIGIN=https:\/\/default.tool.test\/__orbit\/vite\" \"ORBIT_DEV_SERVER_HOST=default.tool.test\" \"ORBIT_DEV_SERVER_PATH=\/__orbit\/vite\" \"__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS=default.tool.test\" \"ORBIT_DEV_SERVER_PORT=5195\" \"\/opt\/orbit-runtime\/current\/bin\/vp\" \"dev\" \"--host=0.0.0.0\" \"--port=5195\" \"--strictPort\" \"--base=\/__orbit\/vite\/\"\nRestart=on-failure\nRestartSec=2\n\n[Install]\nWantedBy=multi-user.target\n",
        "environment": "# Orbit Process 1\nDEV_SERVER_ORIGIN=\"https:\/\/project.example\/own\"\nPRIVATE_SETTING=\"kept\"\n",
        "path": "\/etc\/orbit\/processes\/1\/cf1d0dce8a189c27c2f022251522479e1d7808a812b9231f80a363be1979dca6.env"
    },
    "preset-checkout-supplied": {
        "unit": "[Unit]\nDescription=Orbit process vite\nX-Orbit-Process-ID=1\nAfter=network-online.target\nWants=network-online.target\n\n[Service]\nType=simple\nUser=orbit\nWorkingDirectory=\/fast\/apps\/tool\/default\nEnvironment=PATH=\/usr\/local\/bin:\/opt\/orbit\/composer\/vendor\/bin:\/usr\/bin:\/bin\nEnvironment=NODE_USE_SYSTEM_CA=1\nEnvironmentFile=\/etc\/orbit\/processes\/1\/cf1d0dce8a189c27c2f022251522479e1d7808a812b9231f80a363be1979dca6.env\nEnvironment=ORBIT_DEV_SERVER_ORIGIN=https:\/\/default.tool.test\/__orbit\/vite\nEnvironment=ORBIT_DEV_SERVER_HOST=default.tool.test\nEnvironment=ORBIT_DEV_SERVER_PATH=\/__orbit\/vite\nEnvironment=__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS=default.tool.test\nEnvironment=ORBIT_DEV_SERVER_PORT=5195\nEnvironmentFile=\/etc\/orbit\/vite\/app-instance-1.env\nUnsetEnvironment=VITE_DEV_SERVER_CERT VITE_DEV_SERVER_KEY\nExecStart=\"\/usr\/bin\/env\" \"ORBIT_DEV_SERVER_ORIGIN=https:\/\/default.tool.test\/__orbit\/vite\" \"ORBIT_DEV_SERVER_HOST=default.tool.test\" \"ORBIT_DEV_SERVER_PATH=\/__orbit\/vite\" \"__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS=default.tool.test\" \"\/usr\/local\/bin\/vp\" \"dev\" \"--host=127.0.0.1\" \"--port=${ORBIT_DEV_SERVER_PORT}\" \"--strictPort\" \"--base=\/__orbit\/vite\/\"\nRestart=on-failure\nRestartSec=2\n\n[Install]\nWantedBy=multi-user.target\n",
        "environment": "# Orbit Process 1\nDEV_SERVER_ORIGIN=\"https:\/\/project.example\/own\"\nPRIVATE_SETTING=\"kept\"\n",
        "path": "\/etc\/orbit\/processes\/1\/cf1d0dce8a189c27c2f022251522479e1d7808a812b9231f80a363be1979dca6.env"
    },
    "preset-container-supplied-empty": {
        "unit": "[Unit]\nDescription=Orbit process vite\nX-Orbit-Process-ID=1\nAfter=network.target\n\n[Service]\nType=simple\nUser=orbit\nGroup=orbit\nUMask=0007\nWorkingDirectory=\/srv\/workspace\nEnvironment=HOME=\/home\/orbit\nEnvironment=PATH=\/opt\/orbit-runtime\/current\/bin:\/usr\/local\/bin:\/usr\/bin:\/bin\nEnvironment=NODE_USE_SYSTEM_CA=1\nEnvironmentFile=\/etc\/orbit\/processes\/1\/abe633894ebf499fa0d1278a3cd32628d1eefafbbbbd3f718ad587d888b75e39.env\nEnvironment=ORBIT_DEV_SERVER_ORIGIN=https:\/\/default.tool.test\/__orbit\/vite\nEnvironment=ORBIT_DEV_SERVER_HOST=default.tool.test\nEnvironment=ORBIT_DEV_SERVER_PATH=\/__orbit\/vite\nEnvironment=__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS=default.tool.test\nEnvironment=ORBIT_DEV_SERVER_PORT=5195\nUnsetEnvironment=VITE_DEV_SERVER_CERT VITE_DEV_SERVER_KEY\nExecStart=\"\/usr\/bin\/env\" \"ORBIT_DEV_SERVER_ORIGIN=https:\/\/default.tool.test\/__orbit\/vite\" \"ORBIT_DEV_SERVER_HOST=default.tool.test\" \"ORBIT_DEV_SERVER_PATH=\/__orbit\/vite\" \"__VITE_ADDITIONAL_SERVER_ALLOWED_HOSTS=default.tool.test\" \"ORBIT_DEV_SERVER_PORT=5195\" \"\/opt\/orbit-runtime\/current\/bin\/vp\" \"dev\" \"--host=0.0.0.0\" \"--port=5195\" \"--strictPort\" \"--base=\/__orbit\/vite\/\"\nRestart=on-failure\nRestartSec=2\n\n[Install]\nWantedBy=multi-user.target\n",
        "environment": "# Orbit Process 1\nDEV_SERVER_ORIGIN=\"\"\nPRIVATE_SETTING=\"kept\"\n",
        "path": "\/etc\/orbit\/processes\/1\/abe633894ebf499fa0d1278a3cd32628d1eefafbbbbd3f718ad587d888b75e39.env"
    },
    "ordinary-route-supplied": {
        "unit": "[Unit]\nDescription=Orbit process vite\nX-Orbit-Process-ID=1\nAfter=network.target\n\n[Service]\nType=simple\nUser=orbit\nGroup=orbit\nUMask=0007\nWorkingDirectory=\/srv\/workspace\nEnvironment=HOME=\/home\/orbit\nEnvironment=PATH=\/opt\/orbit-runtime\/current\/bin:\/usr\/local\/bin:\/usr\/bin:\/bin\nEnvironment=NODE_USE_SYSTEM_CA=1\nEnvironmentFile=-\/srv\/workspace\/.env\nEnvironmentFile=\/etc\/orbit\/processes\/1\/cf1d0dce8a189c27c2f022251522479e1d7808a812b9231f80a363be1979dca6.env\nEnvironment=ORBIT_DEV_SERVER_ORIGIN=https:\/\/default.tool.test\/__orbit\/vite\nEnvironment=ORBIT_DEV_SERVER_HOST=default.tool.test\nEnvironment=ORBIT_DEV_SERVER_PATH=\/__orbit\/vite\nEnvironment=ORBIT_DEV_SERVER_PORT=5195\nExecStart=\"\/usr\/bin\/env\" \"ORBIT_DEV_SERVER_ORIGIN=https:\/\/default.tool.test\/__orbit\/vite\" \"ORBIT_DEV_SERVER_HOST=default.tool.test\" \"ORBIT_DEV_SERVER_PATH=\/__orbit\/vite\" \"ORBIT_DEV_SERVER_PORT=5195\" \"\/usr\/bin\/true\"\nRestart=on-failure\nRestartSec=2\n\n[Install]\nWantedBy=multi-user.target\n",
        "environment": "# Orbit Process 1\nDEV_SERVER_ORIGIN=\"https:\/\/project.example\/own\"\nPRIVATE_SETTING=\"kept\"\n",
        "path": "\/etc\/orbit\/processes\/1\/cf1d0dce8a189c27c2f022251522479e1d7808a812b9231f80a363be1979dca6.env"
    },
    "node-supplied": {
        "unit": "[Unit]\nDescription=Orbit process worker\nX-Orbit-Process-ID=1\nAfter=network-online.target\nWants=network-online.target\n\n[Service]\nType=simple\nUser=orbit\nWorkingDirectory=\/home\/orbit\nEnvironment=PATH=\/usr\/local\/bin:\/opt\/orbit\/composer\/vendor\/bin:\/usr\/bin:\/bin\nEnvironment=NODE_USE_SYSTEM_CA=1\nEnvironmentFile=\/etc\/orbit\/processes\/1\/718d988646b0738414e0a0c8d22a16db4b1b4c7b6994a5dcd57d3c0eab04e18d.env\nExecStart=\"\/usr\/bin\/true\"\nRestart=no\nRestartSec=2\n\n[Install]\nWantedBy=multi-user.target\n",
        "environment": "# Orbit Process 1\nDEV_SERVER_ORIGIN=\"https:\/\/project.example\/own\"\n",
        "path": "\/etc\/orbit\/processes\/1\/718d988646b0738414e0a0c8d22a16db4b1b4c7b6994a5dcd57d3c0eab04e18d.env"
    },
    "routeless-supplied": {
        "unit": "[Unit]\nDescription=Orbit process vite\nX-Orbit-Process-ID=1\nAfter=network.target\n\n[Service]\nType=simple\nUser=orbit\nGroup=orbit\nUMask=0007\nWorkingDirectory=\/fast\/apps\/tool\/default\nEnvironment=HOME=\/home\/orbit\nEnvironment=PATH=\/opt\/orbit-runtime\/current\/bin:\/usr\/local\/bin:\/usr\/bin:\/bin\nEnvironment=NODE_USE_SYSTEM_CA=1\nEnvironmentFile=\/etc\/orbit\/processes\/1\/cf1d0dce8a189c27c2f022251522479e1d7808a812b9231f80a363be1979dca6.env\nExecStart=\"\/usr\/bin\/true\"\nRestart=on-failure\nRestartSec=2\n\n[Install]\nWantedBy=multi-user.target\n",
        "environment": "# Orbit Process 1\nDEV_SERVER_ORIGIN=\"https:\/\/project.example\/own\"\nPRIVATE_SETTING=\"kept\"\n",
        "path": "\/etc\/orbit\/processes\/1\/cf1d0dce8a189c27c2f022251522479e1d7808a812b9231f80a363be1979dca6.env"
    }
}
JSON, true, 512, JSON_THROW_ON_ERROR)[$case];
}
