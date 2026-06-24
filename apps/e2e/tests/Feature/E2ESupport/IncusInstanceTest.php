<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\IncusHost;
use App\E2E\Support\IncusInstance;
use Illuminate\Contracts\Process\ProcessResult;
use Mockery as m;

afterEach(function (): void {
    m::close();
});

function incusInstanceResult(string $output = '', bool $successful = true): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn($successful);
    $result->shouldReceive('output')->andReturn($output);
    $result->shouldReceive('errorOutput')->andReturn($successful ? '' : 'failed');

    return $result;
}

it('prefers the live guest IPv4 over stale Incus state', function (): void {
    $commands = [];
    $host = m::mock(IncusHost::class, [E2EConfig::fromEnvironment()])->makePartial();
    $host->shouldReceive('run')
        ->andReturnUsing(function (string $command, ?int $timeoutSeconds = null) use (&$commands): ProcessResult {
            $commands[] = $command;

            if (str_contains($command, 'ip -j -4 address show scope global')) {
                return incusInstanceResult("10.231.7.248\n");
            }

            if (str_contains($command, '/1.0/instances/orbit-e2e-gateway/state')) {
                return incusInstanceResult("10.231.7.249\n");
            }

            return incusInstanceResult();
        });

    $instance = new IncusInstance($host, 'orbit-e2e-gateway', commandTransport: true);

    expect($instance->waitForIpv4())
        ->toBe('10.231.7.248')
        ->and(implode("\n", $commands))
        ->not->toContain('/1.0/instances/orbit-e2e-gateway/state');
});
