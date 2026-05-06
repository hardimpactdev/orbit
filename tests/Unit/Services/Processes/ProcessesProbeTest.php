<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Processes;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Enums\ProcessCrashNotification;
use App\Enums\ProcessRestartPolicy;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Processes\ProcessesProbe;
use App\Services\RuntimeBackend\RuntimeBackendProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->probe = new ProcessesProbe;
});

describe('interface contract', function (): void {
    it('has key and label', function (): void {
        expect($this->probe->key())->toBe('processes');
        expect($this->probe->label())->toBe('Processes');
    });

    it('returns an empty foundation snapshot before live runtime probing is added', function (): void {
        $process = new Process(['name' => 'vite']);

        $snapshot = $this->probe->introspect($process);

        expect($snapshot->isEmpty())->toBeTrue();
    });
});

describe('runtime backend availability', function (): void {
    it('introspects supervisor runtime backend availability on the owner app node', function (): void {
        $app = processableApp();
        $process = processFor($app, ['name' => 'vite']);
        $shell = new ProcessesProbeRecordingRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'supervisor OK', stderr: '', durationMs: 1),
        ]);

        $snapshot = (new ProcessesProbe(runtimeBackendProbe: new RuntimeBackendProbe($shell)))->introspect($process);

        expect($snapshot->get('vite'))->toMatchArray([
            'runtime_backend_available' => true,
            'runtime_backend_exit_code' => 0,
            'runtime_backend_output' => 'supervisor OK',
        ]);
        expect($shell->scripts[0])->toBe('command -v supervisorctl >/dev/null 2>&1 && supervisorctl status >/dev/null 2>&1');
        expect($shell->nodes[0]->is($app->node))->toBeTrue();
    });

    it('detects unavailable supervisor runtime backends and leaves downstream checks to later layers', function (): void {
        $app = processableApp();
        $process = processFor($app, ['name' => 'vite']);

        $snapshot = new ProbeSnapshot([
            'vite' => [
                'runtime_backend_available' => false,
                'runtime_backend_exit_code' => 127,
                'runtime_backend_output' => 'missing supervisorctl',
            ],
        ]);

        $drift = $this->probe->diff($process, $snapshot);

        expect(issue($drift, 'process.runtime_backend_unavailable')?->kind)->toBe(DriftKind::Unverifiable);
        expect(issue($drift, 'process.runtime_backend_unavailable')?->detail)->toMatchArray([
            'node' => $app->node->name,
            'exit_code' => 127,
            'output' => 'missing supervisorctl',
        ]);
    });

    it('does not report runtime backend drift without an owner app node snapshot', function (): void {
        $process = new Process(['name' => 'vite']);

        $drift = $this->probe->diff($process, new ProbeSnapshot([
            'vite' => [
                'runtime_backend_available' => false,
            ],
        ]));

        expect(issue($drift, 'process.runtime_backend_unavailable'))->toBeNull();
    });
});

describe('registry intent', function (): void {
    it('passes complete process records with eligible owner apps and runtime contexts', function (): void {
        $app = processableApp(['name' => 'docs']);
        Workspace::factory()
            ->for($app, 'app')
            ->create([
                'name' => 'feature-docs',
                'path' => "{$app->path}/workspaces/feature-docs",
            ]);
        $process = processFor($app, ['name' => 'vite']);

        $drift = $this->probe->diff($process, new ProbeSnapshot([]));

        expect($drift)->toBe([]);
    });

    it('detects incomplete process records', function (): void {
        $app = processableApp();

        $id = DB::table('processes')->insertGetId([
            'app_id' => $app->id,
            'name' => 'vite',
            'command' => '',
            'restart_policy' => ProcessRestartPolicy::Never->value,
            'crash_notification' => ProcessCrashNotification::None->value,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $process = Process::findOrFail($id);

        $drift = $this->probe->diff($process, new ProbeSnapshot([]));

        expect($drift)->toHaveCount(1);
        expect($drift[0]->family)->toBe('processes');
        expect($drift[0]->key)->toBe('process.record_incomplete');
        expect($drift[0]->kind)->toBe(DriftKind::Missing);
    });

    it('detects unsupported restart policy intent', function (): void {
        $app = processableApp();

        $id = DB::table('processes')->insertGetId([
            'app_id' => $app->id,
            'name' => 'vite',
            'command' => 'npm run dev -- --host=0.0.0.0',
            'restart_policy' => 'sometimes',
            'crash_notification' => ProcessCrashNotification::None->value,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $process = Process::findOrFail($id);

        $drift = $this->probe->diff($process, new ProbeSnapshot([]));

        expect(issue($drift, 'process.record_incomplete')?->kind)->toBe(DriftKind::Missing);
    });

    it('detects unsupported crash notification intent', function (): void {
        $app = processableApp();

        $id = DB::table('processes')->insertGetId([
            'app_id' => $app->id,
            'name' => 'vite',
            'command' => 'npm run dev -- --host=0.0.0.0',
            'restart_policy' => ProcessRestartPolicy::Never->value,
            'crash_notification' => 'pager',
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $process = Process::findOrFail($id);

        $drift = $this->probe->diff($process, new ProbeSnapshot([]));

        expect(issue($drift, 'process.record_incomplete')?->kind)->toBe(DriftKind::Missing);
    });
});

describe('owner app eligibility', function (): void {
    it('requires an owner app on an active app node', function (array $nodeState): void {
        $node = Node::factory()->create($nodeState);
        $app = App::factory()->for($node, 'node')->create();
        $process = processFor($app, ['name' => 'vite']);

        $drift = $this->probe->diff($process, new ProbeSnapshot([]));

        expect(issue($drift, 'process.owner_app_invalid')?->kind)->toBe(DriftKind::Divergent);
    })->with([
        'gateway owner node' => [['role' => 'gateway', 'status' => 'active']],
        'inactive app owner node' => [['role' => 'app', 'status' => 'inactive']],
    ]);
});

describe('runtime context expansion', function (): void {
    it('detects runtime contexts that cannot produce safe supervisor program names', function (): void {
        $app = processableApp(['name' => 'Docs_App']);
        $process = processFor($app, ['name' => 'vite']);

        $drift = $this->probe->diff($process, new ProbeSnapshot([]));

        expect(issue($drift, 'process.runtime_context_unresolved')?->kind)->toBe(DriftKind::Unverifiable);
    });

    it('detects invalid workspace runtime context identity', function (): void {
        $app = processableApp();
        Workspace::factory()
            ->for($app, 'app')
            ->create([
                'name' => 'Feature_App',
                'path' => "{$app->path}/workspaces/feature",
            ]);
        $process = processFor($app, ['name' => 'vite']);

        $drift = $this->probe->diff($process, new ProbeSnapshot([]));

        expect(issue($drift, 'process.runtime_context_unresolved')?->kind)->toBe(DriftKind::Unverifiable);
    });
});

function issue(array $drift, string $key): ?DriftEntry
{
    return collect($drift)->first(fn (DriftEntry $entry): bool => $entry->key === $key);
}

function processableApp(array $overrides = []): App
{
    $node = Node::factory()->create([
        'role' => 'app',
        'status' => 'active',
        'environment' => 'development',
    ]);

    return App::factory()
        ->for($node, 'node')
        ->create($overrides);
}

function processFor(App $app, array $overrides = []): Process
{
    return Process::factory()
        ->for($app, 'app')
        ->create([
            'name' => 'vite',
            'command' => 'npm run dev -- --host=0.0.0.0',
            'restart_policy' => ProcessRestartPolicy::Never,
            'crash_notification' => ProcessCrashNotification::None,
            'sort_order' => 1,
            ...$overrides,
        ]);
}

final class ProcessesProbeRecordingRemoteShell implements RemoteShell
{
    /**
     * @var list<Node>
     */
    public array $nodes = [];

    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(private array $results) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->nodes[] = $node;
        $this->scripts[] = $script;

        return array_shift($this->results) ?? new RemoteShellResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 1,
        );
    }
}
