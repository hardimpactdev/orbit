<?php

declare(strict_types=1);

use App\Services\Doctor\DoctorProxyFamilyProbe;
use App\Services\Doctor\DoctorProxyRouteInventory;
use App\Services\Doctor\DoctorReportRunner;

it('keeps proxy verification and route selection behind focused services', function (): void {
    $runner = new ReflectionClass(DoctorReportRunner::class);
    $constructor = $runner->getConstructor();
    $parameterTypes = collect($constructor?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();

    expect(class_exists(DoctorProxyFamilyProbe::class))
        ->toBeTrue()
        ->and(class_exists(DoctorProxyRouteInventory::class))
        ->toBeTrue()
        ->and($parameterTypes)
        ->toContain(DoctorProxyFamilyProbe::class, DoctorProxyRouteInventory::class)
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

it('removes proxy verification and route inventory from the report runner', function (): void {
    $runnerFile = new ReflectionClass(DoctorReportRunner::class)->getFileName();

    if (! is_string($runnerFile)) {
        throw new LogicException('DoctorReportRunner source file is unavailable.');
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
