<?php

declare(strict_types=1);

use App\Services\Doctor\DoctorNodeFamilyProbe;
use App\Services\Doctor\DoctorNodeProbeRunner;

it('keeps node verification behind a focused family service', function (): void {
    $coordinator = new ReflectionClass(DoctorNodeProbeRunner::class);
    $constructor = $coordinator->getConstructor();
    $parameterTypes = collect($constructor?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();

    expect(class_exists(DoctorNodeFamilyProbe::class))->toBeTrue();
    expect($parameterTypes)->toContain(DoctorNodeFamilyProbe::class);
    expect(
        new ReflectionClass(DoctorNodeFamilyProbe::class)
            ->getMethod('probe')
            ->isPublic(),
    )->toBeTrue();
});

it('keeps node verification dispatch in the node probe coordinator', function (): void {
    $runnerFile = new ReflectionClass(DoctorNodeProbeRunner::class)->getFileName();

    if (! is_string($runnerFile)) {
        throw new LogicException('DoctorNodeProbeRunner source file is unavailable.');
    }

    $runnerSource = file_get_contents($runnerFile);

    expect($runnerSource)
        ->toBeString()
        ->and($runnerSource)
        ->toContain('$this->nodeFamilyProbe->probe(');
});
