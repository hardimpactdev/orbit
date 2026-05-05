<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Mockery as m;
use Tests\E2E\Support\E2EConfig;
use Tests\E2E\Support\E2ETopologyKind;
use Tests\E2E\Support\IncusHost;
use Tests\E2E\Support\IncusTopologyBuilder;

afterEach(function (): void {
    m::close();
});

function incusTopologyBuilderProcessResult(string $output = '', string $errorOutput = '', bool $successful = true): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn($successful);
    $result->shouldReceive('output')->andReturn($output);
    $result->shouldReceive('errorOutput')->andReturn($errorOutput);

    return $result;
}

function incusTopologyBuilderConfig(): E2EConfig
{
    return new E2EConfig(
        providerNames: ['incus'],
        topologyProviderNames: ['incus'],
        host: 'beast',
        sourceImage: 'images:ubuntu/26.04/cloud',
        blankImage: 'orbit-blank-ubuntu-26.04',
        baseImage: 'orbit-base-ubuntu-26.04',
        hcloudServerType: 'cpx11',
        hcloudLocation: 'ash',
        hcloudBlankImage: 'ubuntu-24.04',
        hcloudControlImage: '',
        hcloudGatewayImage: '',
        bootstrapUser: 'provisioner',
        controlUser: 'control',
        instancePrefix: 'orbit-e2e',
        timeoutSeconds: 60,
        cpus: '2',
        memory: '2GiB',
        topologyCpus: '1',
        topologyMemory: '2GiB',
        topologyStateSize: '4GiB',
        incusStoragePool: '',
        incusMaxVmsPerHost: 4,
        dockerHosts: ['local'],
        dockerMaxContainersPerHost: 8,
        keep: false,
    );
}

it('throws when the base image is missing', function (): void {
    $config = E2EConfig::fromEnvironment();

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('imageExists')
        ->with($config->baseImage)
        ->andReturn(false);

    $builder = new IncusTopologyBuilder($host);
    $builder->useBundle('/tmp/orbit-e2e-bundle-test');

    expect(fn () => $builder->build(E2ETopologyKind::Control))
        ->toThrow(RuntimeException::class, "Required source image [{$config->baseImage}] not found");
});

it('throws when no provisioning bundle has been staged', function (): void {
    $config = E2EConfig::fromEnvironment();

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('imageExists')->andReturn(true);

    $builder = new IncusTopologyBuilder($host);

    expect(fn () => $builder->build(E2ETopologyKind::Control))
        ->toThrow(RuntimeException::class, 'No provisioning bundle has been staged');
});

it('throws when a target template instance already exists', function (): void {
    $config = E2EConfig::fromEnvironment();

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('imageExists')->andReturn(true);
    $host->shouldReceive('instanceExists')
        ->with('orbit-template-control-control')
        ->andReturn(true);

    $builder = new IncusTopologyBuilder($host);
    $builder->useBundle('/tmp/orbit-e2e-bundle-test');

    expect(fn () => $builder->build(E2ETopologyKind::Control))
        ->toThrow(RuntimeException::class, 'Template instance [orbit-template-control-control] already exists');
});

it('bakes app node registry rows instead of running node:new during prepared topology builds', function (): void {
    $config = incusTopologyBuilderConfig();
    $commands = [];

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('imageExists')->with($config->baseImage)->andReturn(true);
    $host->shouldReceive('instanceExists')->andReturn(false);
    $host->shouldReceive('waitForCloudInit')->times(4);
    $host->shouldReceive('provisionInstance')->with('orbit-template-control-gateway-dev-prod-control', 'control', '/tmp/orbit-e2e-bundle-test', 'control')->once()->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('provisionInstance')->with('orbit-template-control-gateway-dev-prod-gateway', 'gateway', '/tmp/orbit-e2e-bundle-test')->once()->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('provisionInstance')->with('orbit-template-control-gateway-dev-prod-dev', 'app', '/tmp/orbit-e2e-bundle-test')->once()->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('provisionInstance')->with('orbit-template-control-gateway-dev-prod-prod', 'app', '/tmp/orbit-e2e-bundle-test')->once()->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('stopInstance')->times(4)->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('snapshotInstance')->times(4)->andReturn(incusTopologyBuilderProcessResult());
    $host->shouldReceive('run')->andReturnUsing(function (string $command, ?int $timeoutSeconds = null) use (&$commands): ProcessResult {
        $commands[] = $command;

        if (str_starts_with($command, 'mktemp -d ')) {
            return incusTopologyBuilderProcessResult("/tmp/orbit-topology-builder-test\n");
        }

        if (str_contains($command, 'orbit-template-control-gateway-dev-prod-gateway')) {
            return incusTopologyBuilderProcessResult("10.201.0.11\n");
        }

        if (str_contains($command, 'orbit-template-control-gateway-dev-prod-dev')) {
            return incusTopologyBuilderProcessResult("10.201.0.12\n");
        }

        if (str_contains($command, 'orbit-template-control-gateway-dev-prod-prod')) {
            return incusTopologyBuilderProcessResult("10.201.0.13\n");
        }

        if (str_contains($command, 'orbit-template-control-gateway-dev-prod-control')) {
            return incusTopologyBuilderProcessResult("10.201.0.10\n");
        }

        return incusTopologyBuilderProcessResult();
    });

    $builder = new IncusTopologyBuilder($host);
    $builder->useBundle('/tmp/orbit-e2e-bundle-test');

    $builder->build(E2ETopologyKind::ControlGatewayDevProd);

    $commandOutput = implode("\n", $commands);

    expect($commandOutput)->not->toContain('orbit node:new app-dev-1')
        ->and($commandOutput)->not->toContain('orbit node:new app-prod-1')
        ->and($commandOutput)->toContain('orbit:internal:bake-app-node')
        ->and($commandOutput)->toContain('app-dev-1')
        ->and($commandOutput)->toContain('10.201.0.12')
        ->and($commandOutput)->toContain('10.6.0.4')
        ->and($commandOutput)->toContain('--ssh-user=orbit')
        ->and($commandOutput)->toContain('app-prod-1')
        ->and($commandOutput)->toContain('10.201.0.13')
        ->and($commandOutput)->toContain('10.6.0.5');
});
