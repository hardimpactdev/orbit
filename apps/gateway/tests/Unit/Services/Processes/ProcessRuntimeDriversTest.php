<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Services\Processes\ProcessRuntimeDrivers\DockerProcessRuntimeDriver;
use App\Services\Processes\ProcessRuntimeDrivers\SupervisorProcessRuntimeDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('runs supervisor process lifecycle through the supervisor runtime driver', function (): void {
    $shell = new ProcessRuntimeDriverRecordingShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);
    app()->instance(RemoteShell::class, $shell);

    $node = Node::factory()->create(['name' => 'app-dev-1']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/srv/docs']);
    $process = Process::factory()->forOwner($app)->create([
        'name' => 'queue',
        'command' => 'php artisan queue:work',
        'runtime' => ProcessRuntime::Supervisor,
    ]);

    $driver = app(SupervisorProcessRuntimeDriver::class);
    $runtimeUnit = $driver->runtimeUnitName($app, $process);

    expect($runtimeUnit)->toBe('orbit_docs_main_queue')
        ->and($driver->start($node, $runtimeUnit))->toBeTrue()
        ->and($driver->stop($node, $runtimeUnit))->toBeTrue()
        ->and($driver->restart($node, $runtimeUnit))->toBeTrue()
        ->and($driver->logScript($app, $process, null, $runtimeUnit, 25, false))
        ->toBe("sudo tail -n 25 '/home/orbit/.config/orbit/logs/orbit_docs_main_queue.log'")
        ->and($driver->logScript($app, $process, null, $runtimeUnit, 25, true))
        ->toBe("sudo tail -n 25 -F '/home/orbit/.config/orbit/logs/orbit_docs_main_queue.log'");

    expect($shell->scripts)->toBe([
        "sudo supervisorctl start 'orbit_docs_main_queue'",
        "sudo supervisorctl stop 'orbit_docs_main_queue'",
        "sudo supervisorctl restart 'orbit_docs_main_queue'",
    ]);
});

it('runs docker process lifecycle through the docker runtime driver', function (): void {
    $shell = new ProcessRuntimeDriverRecordingShell([
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
    ]);
    app()->instance(RemoteShell::class, $shell);

    $node = Node::factory()->create(['name' => 'app-dev-1']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/srv/docs']);
    $process = Process::factory()->forOwner($app)->create([
        'name' => 'queue',
        'command' => 'php artisan queue:work',
        'runtime' => ProcessRuntime::Docker,
    ]);

    $driver = app(DockerProcessRuntimeDriver::class);
    $runtimeUnit = $driver->runtimeUnitName($app, $process);

    expect($runtimeUnit)->toBe('orbit_docs_main_queue')
        ->and($driver->start($node, $runtimeUnit))->toBeTrue()
        ->and($driver->stop($node, $runtimeUnit))->toBeTrue()
        ->and($driver->restart($node, $runtimeUnit))->toBeTrue()
        ->and($driver->logScript($app, $process, null, $runtimeUnit, 25, false))
        ->toBe("docker logs --tail 25 'orbit_docs_main_queue' 2>&1")
        ->and($driver->logScript($app, $process, null, $runtimeUnit, 25, true))
        ->toBe("docker logs --tail 25 --follow 'orbit_docs_main_queue' 2>&1");

    expect($shell->scripts)->toBe([
        "docker start 'orbit_docs_main_queue'",
        "docker stop 'orbit_docs_main_queue'",
        "docker restart 'orbit_docs_main_queue'",
    ]);
});

final class ProcessRuntimeDriverRecordingShell implements RemoteShell
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
