<?php

declare(strict_types=1);

use App\Domain\Extensions\ReservedNames;
use App\Domain\Fleet\NodeFootprint;
use App\Domain\Fleet\NodeFootprintArtifact;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Fleet\Footprint\AgentFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\CaddyFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\CaddyPackageFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\PrivateDnsFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\RouteResidueFootprintArtifact;
use App\Infrastructure\Fleet\Footprint\TmpfilesFootprintArtifact;
use App\Infrastructure\Gateway\NativeGatewayFpmConverger;
use App\Infrastructure\ProxyCli\ProxyCliCaddySiteSource;
use App\Infrastructure\ProxyCli\ProxyCliFootprintArtifact;
use Orbit\Extensions\Documents\Infrastructure\DocumentsServiceStartHook;

it('wires contributed Caddy sources into the production renderer', function (): void {
    assertPlugInContributions(NodeCaddyfileRenderer::class, 'contributions', [ProxyCliCaddySiteSource::class]);
});

it('wires start hooks into the production FPM converger', function (): void {
    assertPlugInContributions(NativeGatewayFpmConverger::class, 'startHooks', [DocumentsServiceStartHook::class]);
});

it('keeps the production footprint artifact names in the reserved registry', function (): void {
    $footprint = assertPlugInContributions(NodeFootprint::class, 'artifacts', [
        AgentFootprintArtifact::class,
        CaddyPackageFootprintArtifact::class,
        CaddyFootprintArtifact::class,
        PrivateDnsFootprintArtifact::class,
        RouteResidueFootprintArtifact::class,
        TmpfilesFootprintArtifact::class,
        ProxyCliFootprintArtifact::class,
    ]);
    $artifacts = new ReflectionProperty($footprint, 'artifacts')->getValue($footprint);

    expect(array_map(static fn (NodeFootprintArtifact $artifact): string => $artifact->name(), $artifacts))->toEqualCanonicalizing([...ReservedNames::FootprintArtifacts, 'proxycli']);
});
