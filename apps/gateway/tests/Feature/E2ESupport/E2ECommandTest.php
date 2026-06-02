<?php

declare(strict_types=1);

use App\E2E\Support\E2ECommand;
use App\E2E\Support\E2EInstance;
use App\E2E\Support\E2ETopologyArtifactNamespace;
use App\E2E\Support\SshKeyPair;
use Illuminate\Contracts\Process\ProcessResult;
use Mockery as m;

afterEach(function (): void {
    m::close();
});

function e2eCommandProcessResult(bool $successful, string $output = '', string $errorOutput = ''): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn($successful);
    $result->shouldReceive('output')->andReturn($output);
    $result->shouldReceive('errorOutput')->andReturn($errorOutput);

    return $result;
}

it('throws a runtime exception when an ssh command fails outside the pest assertion context', function (): void {
    $instance = m::mock(E2EInstance::class);
    $instance->shouldReceive('ssh')
        ->with('operator', m::type(SshKeyPair::class), 'orbit node:list', 30)
        ->andReturn(e2eCommandProcessResult(false, 'stdout', 'stderr'));

    expect(fn () => E2ECommand::ssh(
        $instance,
        'operator',
        new SshKeyPair('/tmp/id', '/tmp/id.pub'),
        'orbit node:list',
        timeoutSeconds: 30,
    ))->toThrow(RuntimeException::class, 'SSH command failed: orbit node:list');
});

it('returns the process result when an ssh command succeeds', function (): void {
    $result = e2eCommandProcessResult(true, 'ok');
    $instance = m::mock(E2EInstance::class);
    $instance->shouldReceive('ssh')
        ->andReturn($result);

    expect(E2ECommand::ssh(
        $instance,
        'operator',
        new SshKeyPair('/tmp/id', '/tmp/id.pub'),
        'orbit node:list',
    ))->toBe($result);
});

it('runs gateway artisan through the prepared gateway image by default', function (): void {
    withE2ETopologyEnvironment([], function (): void {
        $command = E2ECommand::gatewayArtisanCommand('route:list');

        expect($command)
            ->toContain('docker run --rm --pull never')
            ->toContain("'orbit-gateway:prepared-current'")
            ->toContain('/root/.ssh:/root/.ssh:ro')
            ->toContain('/home/orbit/.ssh:/home/orbit/.ssh:ro')
            ->not->toContain('orbit-gateway:current')
            ->toContain('artisan route:list');
    });
});

it('runs gateway artisan through the namespaced gateway image for isolated artifacts', function (): void {
    withE2ETopologyEnvironment([
        E2ETopologyArtifactNamespace::EnvironmentVariable => 'Provision Serving',
    ], function (): void {
        $command = E2ECommand::gatewayArtisanCommand('route:list');

        expect($command)
            ->toContain("'orbit-gateway:provision-serving-current'")
            ->not->toContain("'orbit-gateway:prepared-current'")
            ->toContain('artisan route:list');
    });
});
