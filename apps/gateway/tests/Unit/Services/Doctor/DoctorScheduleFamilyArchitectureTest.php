<?php

declare(strict_types=1);

use App\Services\Doctor\DoctorNodeProbeRunner;
use App\Services\Doctor\DoctorScheduleFamilyProbe;

it('keeps schedule verification behind a focused family service', function (): void {
    $coordinator = new ReflectionClass(DoctorNodeProbeRunner::class);
    $constructor = $coordinator->getConstructor();
    $parameterTypes = collect($constructor?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();

    expect(class_exists(DoctorScheduleFamilyProbe::class))
        ->toBeTrue()
        ->and($parameterTypes)
        ->toContain(DoctorScheduleFamilyProbe::class)
        ->and(
            new ReflectionClass(DoctorScheduleFamilyProbe::class)
                ->getMethod('probe')
                ->isPublic(),
        )
        ->toBeTrue();
});

it('keeps schedule dispatch in the coordinator without family inventory details', function (): void {
    $runnerFile = new ReflectionClass(DoctorNodeProbeRunner::class)->getFileName();

    if (! is_string($runnerFile)) {
        throw new LogicException('DoctorNodeProbeRunner source file is unavailable.');
    }

    $runnerSource = file_get_contents($runnerFile);

    expect($runnerSource)
        ->toBeString()
        ->and($runnerSource)
        ->toContain('$this->scheduleFamilyProbe->probe(');
    expect($runnerSource)->not->toContain('private function schedulesForNode(');
    expect($runnerSource)->not->toContain('private function scheduleIssuePayload(');
    expect($runnerSource)->not->toContain('private function scheduleGatewayIssuePayload(');
    expect($runnerSource)->not->toContain('private function scheduleNodeName(');
});
