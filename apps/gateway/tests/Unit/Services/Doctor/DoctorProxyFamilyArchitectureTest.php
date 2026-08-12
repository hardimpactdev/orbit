<?php

declare(strict_types=1);

use App\Services\Doctor\DoctorNodeProbeRunner;
use App\Services\Doctor\DoctorProxyFamilyProbe;
use App\Services\Doctor\DoctorProxyRouteInventory;

it('keeps proxy verification and route selection behind focused services', function (): void {
    $coordinator = new ReflectionClass(DoctorNodeProbeRunner::class);
    $coordinatorTypes = collect($coordinator->getConstructor()?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();
    $familyProbe = new ReflectionClass(DoctorProxyFamilyProbe::class);
    $familyProbeTypes = collect($familyProbe->getConstructor()?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();

    expect(class_exists(DoctorProxyFamilyProbe::class))
        ->toBeTrue()
        ->and(class_exists(DoctorProxyRouteInventory::class))
        ->toBeTrue()
        ->and($coordinatorTypes)
        ->toContain(DoctorProxyFamilyProbe::class)
        ->and($familyProbeTypes)
        ->toContain(DoctorProxyRouteInventory::class)
        ->and(
            new ReflectionClass(DoctorProxyFamilyProbe::class)
                ->getMethod('probe')
                ->isPublic(),
        )
        ->toBeTrue()
        ->and(
            new ReflectionClass(DoctorProxyRouteInventory::class)
                ->getMethod('forScope')
                ->isPublic(),
        )
        ->toBeTrue();
});

it('keeps proxy dispatch in the coordinator without family inventory details', function (): void {
    $runnerFile = new ReflectionClass(DoctorNodeProbeRunner::class)->getFileName();

    if (! is_string($runnerFile)) {
        throw new LogicException('DoctorNodeProbeRunner source file is unavailable.');
    }

    $runnerSource = file_get_contents($runnerFile);

    expect($runnerSource)
        ->toBeString()
        ->and($runnerSource)
        ->toContain('$this->proxyFamilyProbe->probe(')
        ->and($runnerSource)
        ->not->toContain(
            'private function proxyRoutesForScope(',
            'private function probeProxyFamily(',
            'private function proxyIssuePayload(',
            'private function shouldProbeProxyDnsProjection(',
        );
});
