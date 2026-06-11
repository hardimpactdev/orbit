<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EPhaseTimer;
use App\E2E\Support\IncusHost;
use App\E2E\Support\IncusParallelHostTasks;
use Illuminate\Contracts\Process\ProcessResult;
use Mockery as m;

afterEach(function (): void {
    m::close();
});

function incusParallelHostTasksConfig(): E2EConfig
{
    return new E2EConfig(
        providerNames: ['incus'],
        topologyProviderNames: ['incus'],
        host: 'beast',
        sourceImage: '',
        baseImage: '',
        bootstrapUser: 'provisioner',
        operatorUser: 'operator',
        instancePrefix: 'orbit-e2e',
        timeoutSeconds: 60,
        cpus: '2',
        memory: '2GiB',
        topologyCpus: '1',
        topologyMemory: '2GiB',
        topologyRootSize: '16GiB',
        topologyStateSize: '4GiB',
        incusStoragePool: '',
        dockerHosts: ['local'],
        keep: false,
    );
}

function incusParallelHostTasksResult(string $output = '', bool $successful = true, string $errorOutput = ''): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn($successful);
    $result->shouldReceive('output')->andReturn($output);
    $result->shouldReceive('errorOutput')->andReturn($errorOutput);

    return $result;
}

it('builds one script that runs every task in the background and waits on all of them', function (): void {
    $script = IncusParallelHostTasks::script([
        'dev' => "incus exec 'clone-dev' -- sh -lc 'true'",
        'prod' => "incus exec 'clone-prod' -- sh -lc 'true'",
    ]);

    expect($script)
        ->toContain('mktemp -d /tmp/orbit-e2e-parallel-XXXXXX')
        ->toContain("trap 'rm -rf \"\$dir\"' EXIT")
        ->toContain("incus exec 'clone-dev' -- sh -lc 'true'")
        ->toContain("incus exec 'clone-prod' -- sh -lc 'true'")
        ->toContain('& PID_TASK_1=$!')
        ->toContain('& PID_TASK_2=$!')
        ->toContain('wait "$PID_TASK_1"')
        ->toContain('wait "$PID_TASK_2"')
        ->toContain('__orbit_task_timing dev')
        ->toContain('__orbit_task_timing prod')
        ->toContain('__orbit_task_status dev')
        ->toContain('__orbit_task_status prod')
        ->toContain('[ "$STATUS" -eq 0 ]')
        ->not->toContain("\nexit \"\$STATUS\"")
        ->and(strpos($script, '& PID_TASK_2=$!'))->toBeLessThan(strpos($script, 'wait "$PID_TASK_1"'));
});

it('records per-task timings into the timer when the host run succeeds', function (): void {
    $host = m::mock(IncusHost::class, [incusParallelHostTasksConfig()])->makePartial();
    $host->shouldReceive('run')
        ->once()
        ->andReturn(incusParallelHostTasksResult(implode("\n", [
            '__orbit_task_status dev 0',
            '__orbit_task_timing dev 1500',
            '__orbit_task_status prod 0',
            '__orbit_task_timing prod 2500',
        ])));

    $timer = new E2EPhaseTimer;

    IncusParallelHostTasks::run($host, [
        'dev' => 'true',
        'prod' => 'true',
    ], $timer, 'command-ready', timeoutSeconds: 120);

    $events = collect($timer->events())->keyBy('name');

    expect($events)->toHaveKey('command-ready.dev')
        ->toHaveKey('command-ready.prod')
        ->and($events['command-ready.dev']['seconds'])->toBe(1.5)
        ->and($events['command-ready.prod']['seconds'])->toBe(2.5);
});

it('throws with the failed task labels when the host run fails', function (): void {
    $host = m::mock(IncusHost::class, [incusParallelHostTasksConfig()])->makePartial();
    $host->shouldReceive('run')
        ->once()
        ->andReturn(incusParallelHostTasksResult(
            output: implode("\n", [
                '__orbit_task_status dev 0',
                '__orbit_task_status prod 1',
            ]),
            successful: false,
            errorOutput: "task prod failed\nssh probe never became ready",
        ));

    expect(fn () => IncusParallelHostTasks::run(
        $host,
        ['dev' => 'true', 'prod' => 'false'],
        new E2EPhaseTimer,
        'command-ready',
        timeoutSeconds: 120,
        failureMessage: 'Could not wait for prepared clones',
    ))->toThrow(RuntimeException::class, 'Could not wait for prepared clones [prod]');
});

it('treats a failed run without parsed statuses as a failure of every task', function (): void {
    $host = m::mock(IncusHost::class, [incusParallelHostTasksConfig()])->makePartial();
    $host->shouldReceive('run')
        ->once()
        ->andReturn(incusParallelHostTasksResult(successful: false, errorOutput: 'mktemp: failed'));

    expect(fn () => IncusParallelHostTasks::run(
        $host,
        ['dev' => 'true', 'prod' => 'true'],
        new E2EPhaseTimer,
        'phase',
    ))->toThrow(RuntimeException::class, '[dev, prod]');
});

it('does nothing when no tasks are given', function (): void {
    $host = m::mock(IncusHost::class, [incusParallelHostTasksConfig()])->makePartial();
    $host->shouldNotReceive('run');

    IncusParallelHostTasks::run($host, [], new E2EPhaseTimer, 'phase');

    expect(true)->toBeTrue();
});
