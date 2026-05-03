<?php

declare(strict_types=1);

use Mockery as m;
use Tests\E2E\Support\E2EConfig;
use Tests\E2E\Support\E2ETopologyKind;
use Tests\E2E\Support\IncusHost;
use Tests\E2E\Support\IncusTopologyBuilder;

afterEach(function (): void {
    m::close();
});

it('throws when a required source image is missing', function (): void {
    $config = E2EConfig::fromEnvironment();

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('imageExists')
        ->with($config->controlImage)
        ->andReturn(false);

    $builder = new IncusTopologyBuilder($host);

    expect(fn () => $builder->build(E2ETopologyKind::Control))
        ->toThrow(RuntimeException::class, "Required source image [{$config->controlImage}] not found");
});

it('throws when a target template instance already exists', function (): void {
    $config = E2EConfig::fromEnvironment();

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('imageExists')->andReturn(true);
    $host->shouldReceive('instanceExists')
        ->with('orbit-template-control-control')
        ->andReturn(true);

    $builder = new IncusTopologyBuilder($host);

    expect(fn () => $builder->build(E2ETopologyKind::Control))
        ->toThrow(RuntimeException::class, 'Template instance [orbit-template-control-control] already exists');
});

it('checks all required source images per topology kind', function (): void {
    $config = E2EConfig::fromEnvironment();

    $host = m::mock(IncusHost::class, [$config])->makePartial();
    $host->shouldReceive('imageExists')
        ->with($config->controlImage)
        ->andReturn(true);
    $host->shouldReceive('imageExists')
        ->with($config->gatewayImage)
        ->andReturn(true);
    $host->shouldReceive('imageExists')
        ->with($config->blankImage)
        ->andReturn(false);

    $builder = new IncusTopologyBuilder($host);

    expect(fn () => $builder->build(E2ETopologyKind::ControlGatewayDevProd))
        ->toThrow(RuntimeException::class, "Required source image [{$config->blankImage}] not found");
});
