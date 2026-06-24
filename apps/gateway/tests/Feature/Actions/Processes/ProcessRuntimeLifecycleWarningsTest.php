<?php

declare(strict_types=1);

use App\Actions\Processes\AddProcess;
use App\Actions\Processes\EditProcess;
use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Models\Node;
use App\Models\Process;
use App\Services\Processes\ProcessOwnerContext;
use App\Services\Processes\ProcessServiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not start a process runtime unit after apply fails', function (): void {
    $shell = new ProcessRuntimeLifecycleRecordingShell([
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'not a swarm manager', durationMs: 1),
    ]);
    app()->instance(RemoteShell::class, $shell);

    $node = Node::factory()->appDev()->create([
        'name' => 'app-dev-1',
        'wireguard_address' => '10.6.0.4',
    ]);
    $context = new ProcessOwnerContext($node, null, null, $node);

    $result = app(AddProcess::class)->handle(
        context: $context,
        name: 'mysql8',
        command: null,
        restartPolicy: ProcessRestartPolicy::Never,
        crashNotification: ProcessCrashNotification::None,
        start: true,
        runtime: ProcessRuntime::DockerSwarm,
        service: 'mysql',
        version: '8',
    );

    expect(array_column($result['warnings'], 'code'))->toBe(['process.runtime_unit_apply_failed'])
        ->and($shell->scripts)->toHaveCount(1)
        ->and($shell->scripts[0])->toContain('docker service create')
        ->and(implode("\n", $shell->scripts))->not->toContain('docker service update --detach --replicas 1');
});

it('does not restart a process runtime unit after apply fails', function (): void {
    $shell = new ProcessRuntimeLifecycleRecordingShell([
        new RemoteShellResult(exitCode: 1, stdout: '', stderr: 'apply failed', durationMs: 1),
    ]);
    app()->instance(RemoteShell::class, $shell);

    $node = Node::factory()->appDev()->create([
        'name' => 'app-dev-1',
        'wireguard_address' => '10.6.0.4',
    ]);
    $context = new ProcessOwnerContext($node, null, null, $node);
    $descriptor = app(ProcessServiceCatalog::class)->resolve(
        service: 'mysql',
        version: '8',
        runtime: ProcessRuntime::DockerSwarm,
        node: $node,
        processName: 'mysql8',
    );

    Process::factory()->forOwner($node)->create([
        'name' => 'mysql8',
        'command' => $descriptor->command,
        'restart_policy' => ProcessRestartPolicy::Never,
        'crash_notification' => ProcessCrashNotification::None,
        'runtime' => ProcessRuntime::DockerSwarm,
        'runtime_config' => $descriptor->runtimeConfig,
    ]);

    $result = app(EditProcess::class)->handle(
        context: $context,
        name: 'mysql8',
        changes: ['command' => 'mysqld --verbose'],
        restart: true,
    );

    expect(array_column($result['warnings'], 'code'))->toBe(['process.runtime_unit_apply_failed'])
        ->and($shell->scripts)->toHaveCount(1)
        ->and($shell->scripts[0])->toContain('docker service create')
        ->and(implode("\n", $shell->scripts))->not->toContain('docker service update --detach --force');
});

final class ProcessRuntimeLifecycleRecordingShell implements RemoteShell
{
    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
        public array $scripts = [],
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}
