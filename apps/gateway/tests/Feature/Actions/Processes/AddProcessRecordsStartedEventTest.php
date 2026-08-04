<?php

declare(strict_types=1);

use App\Actions\Processes\AddProcess;
use App\Contracts\RemoteShell;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessEventType;
use App\Enums\ProcessRestartPolicy;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\ProcessEvent;
use App\Models\Project;
use App\Services\Processes\ProcessOwnerContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records a started process event when AddProcess successfully starts runtime units', function (): void {
    $appNode = createTestAppHostNode(['name' => 'app-1']);
    $app = Project::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
    $instance = AppInstance::factory()->create([
        'app_id' => $app->id,
        'name' => 'development',
        'driver_config' => new OrbitAppInstanceDriverConfigData(node_id: $appNode->id),
    ]);
    $context = new ProcessOwnerContext(
        node: $appNode,
        app: $app,
        workspace: null,
        owner: $app,
        appInstance: $instance,
    );

    app()->instance(RemoteShell::class, new class implements RemoteShell {
        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
        }
    });

    app(AddProcess::class)->handle(
        context: $context,
        name: 'vite',
        command: 'npm run dev',
        restartPolicy: ProcessRestartPolicy::Never,
        crashNotification: ProcessCrashNotification::None,
        start: true,
        runtime: ProcessRuntime::Systemd,
    );

    $types = ProcessEvent::query()
        ->orderBy('id')
        ->pluck('event')
        ->map(
            static fn (ProcessEventType $type): string => $type->value,
        )
        ->all();

    expect($types)
        ->toBe(['starting', 'started'])
        ->and(ProcessEvent::query()->where('event', ProcessEventType::Started)->first()?->unit_name)
        ->toContain('vite');
});

it('records starting then failed when the runtime backend fails to start', function (): void {
    $appNode = createTestAppHostNode(['name' => 'app-1']);
    $app = Project::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
    $instance = AppInstance::factory()->create([
        'app_id' => $app->id,
        'name' => 'development',
        'driver_config' => new OrbitAppInstanceDriverConfigData(node_id: $appNode->id),
    ]);
    $context = new ProcessOwnerContext(
        node: $appNode,
        app: $app,
        workspace: null,
        owner: $app,
        appInstance: $instance,
    );

    app()->instance(RemoteShell::class, new class implements RemoteShell {
        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            if (str_contains($script, 'systemctl start') || str_contains($script, 'start')) {
                return new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'failed', durationMs: 1);
            }

            return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
        }
    });

    $result = app(AddProcess::class)->handle(
        context: $context,
        name: 'vite',
        command: 'npm run dev',
        restartPolicy: ProcessRestartPolicy::Never,
        crashNotification: ProcessCrashNotification::None,
        start: true,
        runtime: ProcessRuntime::Systemd,
    );

    $types = ProcessEvent::query()
        ->orderBy('id')
        ->pluck('event')
        ->map(
            static fn (ProcessEventType $type): string => $type->value,
        )
        ->all();

    expect($types)
        ->toBe(['starting', 'failed'])
        ->and($result['warnings'])
        ->not->toBeEmpty();
});
