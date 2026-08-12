<?php

declare(strict_types=1);

use App\Services\Doctor\DoctorNodeProbeRunner;
use App\Services\Doctor\DoctorWorkspaceFamilyProbe;

it('keeps workspace verification behind a focused family service', function (): void {
    $coordinator = new ReflectionClass(DoctorNodeProbeRunner::class);
    $constructor = $coordinator->getConstructor();
    $parameterTypes = collect($constructor?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();

    expect(class_exists(DoctorWorkspaceFamilyProbe::class))
        ->toBeTrue()
        ->and($parameterTypes)
        ->toContain(DoctorWorkspaceFamilyProbe::class)
        ->and(
            new ReflectionClass(DoctorWorkspaceFamilyProbe::class)
                ->getMethod('probe')
                ->isPublic(),
        )
        ->toBeTrue();
});

it('keeps workspace dispatch in the coordinator without family inventory details', function (): void {
    $runnerFile = new ReflectionClass(DoctorNodeProbeRunner::class)->getFileName();

    if (! is_string($runnerFile)) {
        throw new LogicException('DoctorNodeProbeRunner source file is unavailable.');
    }

    $runnerSource = file_get_contents($runnerFile);

    expect($runnerSource)
        ->toBeString()
        ->and($runnerSource)
        ->toContain('$this->workspaceFamilyProbe->probe(')
        ->and($runnerSource)
        ->not->toContain('private function workspacesForNode(')->and($runnerSource)
        ->not->toContain('private function workspaceIssuePayload(');
});
