<?php

declare(strict_types=1);

use App\Actions\Processes\ShowProcessLogsAction;
use App\Domain\Hibernation\HibernationException;
use App\Domain\Instances\InstanceContainerPlacement;
use App\Domain\Logs\LogReadLimit;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessRuntimeStatusIndex;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Requests\Processes\ProcessLogsRequest;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Hibernation\RemoteInstanceRuntimeReadiness;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ContainerProcessInspection;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Process\Process as LocalProcess;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/fixture/key');
    app()->instance(SshKeyProvider::class, $keys);
    $hosts = Mockery::mock(KnownHostsStore::class);
    $hosts->shouldReceive('path')->andReturn('/fixture/known_hosts');
    app()->instance(KnownHostsStore::class, $hosts);
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldNotReceive('connect');
    app()->instance(WebSocketClient::class, $socket);
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $agents);
});

/** @return array{Instance, Process} */
function directContainerProcess(?Node $node = null, string $incusProject = 'orbit-instances'): array
{
    $number = Project::query()->count() + 1;
    $node ??= Node::withoutEvents(fn (): Node => Node::query()->forceCreate(['name' => 'node-'.$number,
        'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '192.0.2.'.$number,
        'public_ssh_host' => '192.0.2.'.$number, 'user' => 'nckrtl', 'instance_bridge' => '10.253.'.$number.'.0/24']));
    $project = Project::query()->create(['name' => 'fixture-'.$number, 'slug' => 'fixture-'.$number,
        'type' => 'node-package', 'repository_url' => 'https://github.com/hardimpactdev/fixture-'.$number, 'root' => '.']);
    $instance = Instance::query()->create(['node_id' => $node->id, 'project_id' => $project->id, 'name' => 'default',
        'status' => 'active', 'first_setup_pending' => false, 'checkout_path' => '/container-fixture/'.$number, 'branch' => 'main']);
    $prefix = substr($node->instance_bridge, 0, strrpos($node->instance_bridge, '.') + 1);
    InstanceRuntime::query()->create(['instance_id' => $instance->id, 'node_id' => $node->id,
        'incus_project' => $incusProject, 'container_name' => 'instance-'.$instance->id,
        'bridge_address' => $prefix.($number + 1), 'runtime_version' => 'fixture', 'agent_version' => 'fixture', 'power_state' => 'running']);
    $process = Process::query()->create(['owner_type' => 'instance', 'owner_id' => $instance->id, 'name' => 'worker',
        'runtime' => 'systemd', 'runtime_config' => ['command' => ['/usr/bin/true'], 'environment' => ['QUEUE_TOKEN' => 'private-queue-token']],
        'desired_state' => 'running', 'status' => 'active']);

    return [$instance, $process];
}

it('reads twenty container Processes across four Instances in one bounded call to their Node', function (string $incusProject): void {
    $this->freezeTime();
    [$instance, $first] = directContainerProcess(incusProject: $incusProject);
    $processes = collect([$first]);
    for ($i = 1; $i < 4; $i++) {
        [$other, $process] = directContainerProcess($instance->node, $incusProject);
        $processes->push($process);
    }
    foreach ($processes->all() as $process) {
        for ($i = 1; $i < 5; $i++) {
            $processes->push(Process::query()->create([...$process->only(['owner_type', 'owner_id', 'runtime', 'runtime_config', 'desired_state', 'status']), 'name' => 'worker-'.$i]));
        }
    }
    $ssh = Mockery::mock(SshExecutor::class);
    $ssh->shouldReceive('execute')->once()->withArgs(function (SshConnection $connection, RemoteCommand $command) use ($processes, $incusProject): bool {
        expect($connection->user)->toBe('nckrtl')->and($connection->identityFile)->toBe('/fixture/key')
            ->and($connection->knownHostsFile)->toBe('/fixture/known_hosts')->and($connection->connectTimeout)->toBe(2)
            ->and($connection->commandTimeout)->toBe(4.0)->and($command->timeout)->toBe(4.0);
        expect(array_slice($command->arguments, 0, 5))->toBe(['timeout', '3', 'python3', '-', 'states']);
        expect(count($command->arguments))->toBe(65);
        foreach ($processes->values() as $index => $process) {
            expect(array_slice($command->arguments, 5 + $index * 3, 3))->toBe([
                $incusProject, 'instance-'.$process->owner_id, "orbit-process-{$process->id}-{$process->name}.service",
            ]);
        }

        return true;
    })->andReturn(new CommandResult(0, json_encode(array_fill(0, 20, 'active')), '', 1, false));
    app()->instance(SshExecutor::class, $ssh);
    $observations = app(ProcessRuntimeStatusIndex::class)->observations($processes);
    expect($observations)->toHaveCount(20);
    foreach ($observations as $observation) {
        expect($observation)->toBe(['runtime_status' => 'active', 'observed_at' => now()->toISOString(), 'runtime_error_code' => null]);
    }
    expect(Operation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with(InstanceContainerPlacement::projects());

it('isolates an unreachable or malformed Node reply and clears the reason after recovery', function (string $failure): void {
    [$instance, $process] = directContainerProcess();
    [$other, $otherProcess] = directContainerProcess();
    $ssh = Mockery::mock(SshExecutor::class);
    $bad = true;
    $ssh->shouldReceive('execute')->times(3)->andReturnUsing(function (SshConnection $connection) use ($instance, $failure, &$bad): CommandResult {
        if ($connection->host === $instance->node->wireguard_ip && $bad) {
            $bad = false;
            if ($failure === 'timeout') {
                throw new RuntimeException('private transport diagnostics');
            }

            return new CommandResult($failure === 'exit' ? 124 : 0, $failure === 'invalid state' ? '["private reply"]' : 'broken', '', 1, false);
        }

        return new CommandResult(0, '["active"]', '', 1, false);
    });
    app()->instance(SshExecutor::class, $ssh);
    $observations = app(ProcessRuntimeStatusIndex::class)->observations(collect([$process, $otherProcess]));
    expect($observations[$process->id]['runtime_status'])->toBe('unknown')
        ->and($observations[$process->id]['runtime_error_code'])->toBe('process.target_unavailable')
        ->and($observations[$process->id]['observed_at'])->not->toBeNull()
        ->and($observations[$otherProcess->id]['runtime_status'])->toBe('active');
    $recovered = app(ProcessRuntimeStatusIndex::class)->observations(collect([$process]));
    expect($recovered[$process->id]['runtime_status'])->toBe('active')->and($recovered[$process->id]['runtime_error_code'])->toBeNull();
    expect(Operation::query()->count())->toBe(0);
})->with(['timeout', 'exit', 'invalid JSON', 'invalid state']);

it('tails once with resolved names and applies the host ordering and redaction', function (int $lines, string $incusProject): void {
    [$instance, $process] = directContainerProcess(incusProject: $incusProject);
    $ssh = Mockery::mock(SshExecutor::class);
    $ssh->shouldReceive('execute')->once()->withArgs(function (SshConnection $connection, RemoteCommand $command) use ($lines, $instance, $process, $incusProject): bool {
        expect($command->arguments)->toBe(['timeout', '3', 'python3', '-', 'logs', (string) $lines, $incusProject,
            'instance-'.$instance->id, "orbit-process-{$process->id}-worker.service"])
            ->and($command->maxOutputBytes)->toBe(LogReadLimit::Bytes);

        return true;
    })->andReturn(new CommandResult(0, "2026-11-11 worker: private-queue-token\n2026-11-10 worker: first\ncut partial", '', 1, false));
    app()->instance(SshExecutor::class, $ssh);
    expect(app(ShowProcessLogsAction::class)->execute($process, $lines))
        ->toBe("2026-11-10 worker: first\n2026-11-11 worker: [REDACTED]\n");
    expect(Operation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([1, 1000])->with(InstanceContainerPlacement::projects());

it('returns only a safe log error when its Node is unavailable', function (bool $throws): void {
    [$instance, $process] = directContainerProcess();
    $ssh = Mockery::mock(SshExecutor::class);
    $ssh->shouldReceive('execute')->once()->andReturnUsing(function () use ($throws): CommandResult {
        if ($throws) {
            throw new RuntimeException('private transport diagnostics');
        }

        return new CommandResult(1, 'private journal bytes', 'private stderr', 1, false);
    });
    app()->instance(SshExecutor::class, $ssh);
    expect(fn () => app(ShowProcessLogsAction::class)->execute($process, 100))->toThrow(function (ResourceOperationException $error): void {
        expect($error->errorCode)->toBe('process.target_unavailable')->and($error->status)->toBe(503)
            ->and($error->getMessage())->toBe('The container Process could not be read.');
    });
})->with([false, true]);

it('rejects shell text in the line count at the request boundary', function (): void {
    $rules = (new ProcessLogsRequest)->rules();
    expect(Validator::make(['lines' => '1; touch /tmp/injected'], $rules)->fails())->toBeTrue();
});

it('bounds the only caller argument before SSH', function (int $lines): void {
    [$instance, $process] = directContainerProcess();
    $ssh = Mockery::mock(SshExecutor::class);
    $ssh->shouldNotReceive('execute');
    app()->instance(SshExecutor::class, $ssh);
    expect(fn () => app(ContainerProcessInspection::class)->logs($instance, $process, $lines))->toThrow(ResourceOperationException::class);
})->with([0, -1, 1001, PHP_INT_MAX]);

it('refuses injected stored names and another Instances Process before SSH', function (string $mutation): void {
    [$instance, $process] = directContainerProcess();
    match ($mutation) {
        'unit' => $process->name = 'worker;id',
        'container' => $instance->runtime->container_name = 'instance-999',
        'project' => $instance->runtime->incus_project = 'default',
        'owner' => $process->owner_id = $instance->id + 1,
    };
    $ssh = Mockery::mock(SshExecutor::class);
    $ssh->shouldNotReceive('execute');
    app()->instance(SshExecutor::class, $ssh);
    expect(fn () => app(ContainerProcessInspection::class)->logs($instance, $process, 100))->toThrow(ResourceOperationException::class);
    expect(app(ContainerProcessInspection::class)->states([['instance' => $instance, 'process' => $process]])[$process->id]['runtime_status'])->toBe('unknown');
})->with(['unit', 'container', 'project', 'owner']);

it('preserves wake readiness outcomes through the direct state read', function (string $state, ?string $error): void {
    [$instance, $process] = directContainerProcess();
    $ssh = Mockery::mock(SshExecutor::class);
    $ssh->shouldReceive('execute')->once()->andReturn(new CommandResult(0, json_encode([$state]), '', 1, false));
    app()->instance(SshExecutor::class, $ssh);
    $ready = new RemoteInstanceRuntimeReadiness(app(ProcessRuntimeManager::class), timeoutSeconds: 0);
    if ($error === null) {
        $ready->waitUntilReady($instance, [$process]);
    } else {
        expect(fn () => $ready->waitUntilReady($instance, [$process]))->toThrow(function (HibernationException $failure) use ($error): void {
            expect($failure->errorCode)->toBe($error)->and($failure->status)->toBe(502);
        });
    }
})->with([['active', null], ['failed', 'hibernation.runtime_failed'], ['inactive', 'hibernation.runtime_not_ready'], ['unknown', 'hibernation.runtime_not_ready']]);

/** Execute the production read program with a local Incus stand-in, never a live container. */
function runContainerReadFixture(array $arguments, string $scenario, string $journal = ''): LocalProcess
{
    $folder = sys_get_temp_dir().'/container-read-'.bin2hex(random_bytes(6));
    mkdir($folder, 0700);
    file_put_contents($folder.'/journal', $journal);
    copy(base_path('tests/Fixtures/container-process-incus.py'), $folder.'/incus');
    chmod($folder.'/incus', 0700);
    $read = new LocalProcess(['timeout', '3', 'python3', '-', ...$arguments], env: [
        'PATH' => $folder.':/usr/bin:/bin', 'PROCESS_READ_FIXTURE' => $folder, 'PROCESS_READ_CASE' => $scenario, 'PROCESS_READ_PROJECT' => $arguments[$arguments[0] === 'logs' ? 2 : 1],
    ]);
    $read->setInput('INSTANCE_PROJECTS = '.json_encode(InstanceContainerPlacement::projects(), JSON_THROW_ON_ERROR)."\n".file_get_contents(resource_path('processes/container-process-read.py')));
    try {
        $read->run();
    } finally {
        unlink($folder.'/incus');
        unlink($folder.'/journal');
        rmdir($folder);
    }

    return $read;
}

it('reads real fixed argv for stopped or missing containers and missing units', function (string $scenario, string $expected, string $incusProject): void {
    $read = runContainerReadFixture(['states', $incusProject, 'instance-1', 'orbit-process-2-worker.service'], $scenario);
    expect($read->isSuccessful())->toBeTrue()->and(json_decode($read->getOutput(), true))->toBe([$expected]);
    $logs = runContainerReadFixture(['logs', '10', $incusProject, 'instance-1', 'orbit-process-2-worker.service'], $scenario);
    expect($logs->isSuccessful())->toBe($scenario === 'missing-unit')->and($logs->getOutput())->toBe('');
})->with([['stopped', 'unknown'], ['missing-container', 'unknown'], ['missing-unit', 'inactive']])->with(InstanceContainerPlacement::projects());

it('cuts huge entries and output at the host limits in the real fixed reader', function (string $incusProject): void {
    $prefix = '2026-11-11T00:00:00+00:00 node worker[2]: ';
    $journal = $prefix.str_repeat('é', 200000)."\n";
    $read = runContainerReadFixture(['logs', '1000', $incusProject, 'instance-1', 'orbit-process-2-worker.service'], 'active', $journal);
    expect($read->isSuccessful())->toBeTrue()
        ->and($read->getOutput())->toBe($prefix.str_repeat('é', 131072)."\n".str_repeat(' ', strlen($prefix))."[orbit] message cut at 256 KiB\n");
    $read = runContainerReadFixture(['logs', '1000', $incusProject, 'instance-1', 'orbit-process-2-worker.service'], 'active', str_repeat($prefix.str_repeat('x', 10000)."\n", 500));
    expect($read->isSuccessful())->toBeTrue()->and(strlen($read->getOutput()))->toBe(LogReadLimit::Bytes)
        ->and(LogReadLimit::journalInTimeOrder($read->getOutput()))->not->toEndWith('x');
})->with(InstanceContainerPlacement::projects());
