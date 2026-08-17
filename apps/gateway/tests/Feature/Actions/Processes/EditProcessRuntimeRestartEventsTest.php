<?php

declare(strict_types=1);

use App\Actions\Processes\EditProcess;
use App\Actions\Processes\EditProcessRuntimeUnits;
use App\Contracts\RemoteShell;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\ProcessEventType;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\ProcessEvent;
use App\Models\Workspace;
use App\Services\Processes\ProcessOwnerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{context: ProcessOwnerContext, process: Process}
 */
function editProcessRestartFixture(string $processName = 'vite'): array
{
    $node = createTestAppHostNode(['name' => 'app-1']);
    $app = App::factory()->create(['name' => 'docs']);
    $instance = Instance::factory()->create([
        'app_id' => $app->id,
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(node_id: $node->id),
    ]);
    $process = Process::factory()
        ->forOwner($app, $node)
        ->create([
            'instance_id' => $instance->id,
            'name' => $processName,
        ]);

    return [
        'context' => ProcessOwnerContext::forInstance($node, $instance),
        'process' => $process,
    ];
}

/**
 * @param  list<RemoteShellResult>|\RuntimeException  $results
 */
function bindEditProcessRestartShell(array|\RuntimeException $results): void
{
    if ($results instanceof RuntimeException) {
        app()->instance(RemoteShell::class, new class($results) implements RemoteShell {
            public function __construct(
                private RuntimeException $exception,
            ) {}

            public function run(Node $node, string $script, array $options = []): RemoteShellResult
            {
                if (
                    str_contains($script, 'restart')
                    || str_contains($script, 'systemctl restart')
                    || str_contains($script, 'launchctl')
                ) {
                    throw $this->exception;
                }

                return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
            }
        });

        return;
    }

    app()->instance(RemoteShell::class, new class($results) implements RemoteShell {
        /** @param  list<RemoteShellResult>  $results */
        public function __construct(
            private array $results,
        ) {}

        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            if (
                str_contains($script, 'restart')
                || str_contains($script, 'systemctl restart')
            ) {
                return (
                    array_shift($this->results) ?? new RemoteShellResult(
                        exitCode: 0,
                        stdout: '',
                        stderr: '',
                        durationMs: 1,
                    )
                );
            }

            return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
        }
    });
}

/**
 * @return list<string>
 */
function editRestartEventTypes(): array
{
    return ProcessEvent::query()
        ->orderBy('id')
        ->pluck('event')
        ->map(
            static fn (ProcessEventType $type): string => $type->value,
        )
        ->all();
}

it('records restarting then started for process:update --restart success', function (): void {
    $fixture = editProcessRestartFixture();
    bindEditProcessRestartShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);

    $result = app(EditProcess::class)->handle(
        context: $fixture['context'],
        name: 'vite',
        changes: ['command' => 'npm run dev -- --host'],
        restart: true,
    );

    expect(editRestartEventTypes())
        ->toBe(['restarting', 'started'])
        ->and(array_column($result['warnings'], 'code'))
        ->not->toContain('process.runtime_unit_restart_failed');
});

it('records restarting then failed when process:update --restart returns false', function (): void {
    $fixture = editProcessRestartFixture();
    bindEditProcessRestartShell([
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'restart failed', durationMs: 1),
    ]);

    $result = app(EditProcess::class)->handle(
        context: $fixture['context'],
        name: 'vite',
        changes: ['command' => 'npm run dev -- --host'],
        restart: true,
    );

    expect(editRestartEventTypes())
        ->toBe(['restarting', 'failed'])
        ->and(array_column($result['warnings'], 'code'))
        ->toContain('process.runtime_unit_restart_failed');
});

it('records failed then rethrows when process:update --restart throws', function (): void {
    $fixture = editProcessRestartFixture();
    bindEditProcessRestartShell(new RuntimeException('restart boom'));

    expect(fn () => app(EditProcess::class)->handle(
        context: $fixture['context'],
        name: 'vite',
        changes: ['command' => 'npm run dev -- --host'],
        restart: true,
    ))
        ->toThrow(RuntimeException::class, 'restart boom');

    expect(editRestartEventTypes())->toBe(['restarting', 'failed']);
});

it('snapshots the renamed process name on restart lifecycle events', function (): void {
    $fixture = editProcessRestartFixture('vite');
    bindEditProcessRestartShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);

    app(EditProcess::class)->handle(
        context: $fixture['context'],
        name: 'vite',
        changes: [
            'name' => 'vite-dev',
            'command' => 'npm run dev -- --host',
        ],
        restart: true,
    );

    $names = ProcessEvent::query()
        ->orderBy('id')
        ->pluck('process_name')
        ->all();

    expect(editRestartEventTypes())
        ->toBe(['restarting', 'started'])
        ->and($names)
        ->toBe(['vite-dev', 'vite-dev']);
});

it('records ordered restart events when EditProcessRuntimeUnits::restart is invoked directly', function (): void {
    $fixture = editProcessRestartFixture();
    bindEditProcessRestartShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);

    $warnings = app(EditProcessRuntimeUnits::class)->restart(
        $fixture['context'],
        $fixture['process']->fresh(),
        [['name' => 'orbit_docs_development_main_vite', 'context' => 'main']],
    );

    expect($warnings)
        ->toBeEmpty()
        ->and(editRestartEventTypes())
        ->toBe(['restarting', 'started']);
});

it('scopes multi-context process:update --restart events per runtime unit workspace', function (): void {
    $node = createTestAppHostNode(['name' => 'app-1']);
    $app = App::factory()->create(['name' => 'docs']);
    $instance = Instance::factory()->create([
        'app_id' => $app->id,
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(node_id: $node->id),
    ]);
    $workspace = Workspace::factory()->create([
        'app_id' => $app->id,
        'instance_id' => $instance->id,
        'name' => 'feature-a',
    ]);
    $process = Process::factory()
        ->forOwner($app, $node)
        ->create([
            'instance_id' => $instance->id,
            'name' => 'vite',
        ]);
    $context = ProcessOwnerContext::forInstance($node, $instance);

    bindEditProcessRestartShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);

    $mainUnit = 'orbit_docs_development_main_vite';
    $workspaceUnit = 'orbit_docs_development_feature_a_vite';

    $warnings = app(EditProcessRuntimeUnits::class)->restart(
        $context,
        $process->fresh(),
        [
            ['name' => $mainUnit, 'context' => 'main'],
            ['name' => $workspaceUnit, 'context' => 'feature-a'],
        ],
    );

    $events = ProcessEvent::query()
        ->orderBy('id')
        ->get(['event', 'unit_name', 'workspace_id', 'process_name']);

    expect($warnings)
        ->toBeEmpty()
        ->and(
            $events
                ->pluck('event')
                ->map(static fn (ProcessEventType $type): string => $type->value)
                ->all(),
        )
        ->toBe(['restarting', 'started', 'restarting', 'started'])
        ->and($events->pluck('unit_name')->all())
        ->toBe([$mainUnit, $mainUnit, $workspaceUnit, $workspaceUnit])
        ->and($events->pluck('workspace_id')->all())
        ->toBe([null, null, $workspace->id, $workspace->id])
        ->and($events->pluck('process_name')->unique()->values()->all())
        ->toBe(['vite']);

    $mainScopeIds = ProcessEvent::query()
        ->where('instance_id', $instance->id)
        ->where('node_id', $node->id)
        ->whereNull('workspace_id')
        ->orderBy('id')
        ->pluck('unit_name')
        ->all();
    $workspaceScopeIds = ProcessEvent::query()
        ->where('instance_id', $instance->id)
        ->where('node_id', $node->id)
        ->where('workspace_id', $workspace->id)
        ->orderBy('id')
        ->pluck('unit_name')
        ->all();

    expect($mainScopeIds)
        ->toBe([$mainUnit, $mainUnit])
        ->and($workspaceScopeIds)
        ->toBe([$workspaceUnit, $workspaceUnit]);

    // List status for main (workspace null) must not derive from workspace unit terminals.
    $mainLast = ProcessEvent::query()
        ->where('process_id', $process->id)
        ->where('instance_id', $instance->id)
        ->whereNull('workspace_id')
        ->latest('id')
        ->first();
    $workspaceLast = ProcessEvent::query()
        ->where('process_id', $process->id)
        ->where('instance_id', $instance->id)
        ->where('workspace_id', $workspace->id)
        ->latest('id')
        ->first();

    expect($mainLast?->unit_name)
        ->toBe($mainUnit)
        ->and($mainLast?->event)
        ->toBe(ProcessEventType::Started)
        ->and($workspaceLast?->unit_name)
        ->toBe($workspaceUnit)
        ->and($workspaceLast?->event)
        ->toBe(ProcessEventType::Started)
        ->and($mainLast?->id)
        ->not->toBe($workspaceLast?->id);
});
