<?php

declare(strict_types=1);

use App\Services\Doctor\DoctorReportRunner;
use App\Services\Doctor\DoctorToolFamilyProbe;

it('keeps tool verification behind a focused family service', function (): void {
    $runner = new ReflectionClass(DoctorReportRunner::class);
    $constructor = $runner->getConstructor();
    $parameterTypes = collect($constructor?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();

    expect(class_exists(DoctorToolFamilyProbe::class))
        ->toBeTrue()
        ->and($parameterTypes)
        ->toContain(DoctorToolFamilyProbe::class)
        ->and(
            new ReflectionClass(DoctorToolFamilyProbe::class)
                ->getMethod('probe')
                ->isPublic(),
        )
        ->toBeTrue();
});

it('removes tool verification and inventory from the report runner', function (): void {
    $runnerFile = new ReflectionClass(DoctorReportRunner::class)->getFileName();

    if (! is_string($runnerFile)) {
        throw new LogicException('DoctorReportRunner source file is unavailable.');
    }

    $runnerSource = file_get_contents($runnerFile);

    expect($runnerSource)
        ->toBeString()
        ->and($runnerSource)
        ->toContain('$this->toolFamilyProbe->probe(')
        ->and($runnerSource)
        ->not->toContain('private function toolsForNode(')->and($runnerSource)
        ->not->toContain('private function toolIssuePayload(');
});
