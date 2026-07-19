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

it('carries the exact host source path for a source-mounted instance', function (): void {
    $host = m::mock(IncusHost::class, [E2EConfig::fromEnvironment()])->makePartial();
    $instance = new IncusInstance(
        $host,
        'orbit-e2e-gateway',
        commandTransport: true,
        sourceMountedCheckout: true,
        hostSourcePath: '/tmp/orbit-source/retained/dev-abc123',
    );

    expect($instance->hostSourcePath())->toBe('/tmp/orbit-source/retained/dev-abc123');
});

it('requires an exact host source path for a source-mounted instance', function (): void {
    $host = m::mock(IncusHost::class, [E2EConfig::fromEnvironment()])->makePartial();

    expect(fn () => new IncusInstance(
        $host,
        'orbit-e2e-gateway',
        commandTransport: true,
        sourceMountedCheckout: true,
    ))
        ->toThrow(InvalidArgumentException::class, 'requires its exact host source path');
});

it('streams oversized guest commands instead of exceeding the ssh control message limit', function (): void {
    $hostCommand = null;
    $input = null;
    $host = m::mock(IncusHost::class, [E2EConfig::fromEnvironment()])->makePartial();
    $host->shouldReceive('run')->never();
    $host
        ->shouldReceive('runWithInput')
        ->once()
        ->andReturnUsing(function (string $command, string $script) use (&$hostCommand, &$input): ProcessResult {
            $hostCommand = $command;
            $input = $script;

            return incusInstanceResult();
        });

    $payload = str_repeat(string: 'x', times: 4097);

    new IncusInstance($host, 'orbit-e2e-gateway', commandTransport: true)->exec($payload);

    expect($hostCommand)
        ->toBeString()
        ->toContain("incus exec 'orbit-e2e-gateway' -- bash -s")
        ->and($input)
        ->toBeString()
        ->toStartWith("set -euo pipefail\n")
        ->toContain($payload);
});
