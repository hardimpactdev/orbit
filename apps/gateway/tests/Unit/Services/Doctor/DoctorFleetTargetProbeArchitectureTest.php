<?php

declare(strict_types=1);

use App\Services\Doctor\DoctorFleetTargetProbe;
use App\Services\Doctor\DoctorIssueFactory;
use App\Services\Doctor\DoctorNodeFamilyResolver;
use App\Services\Doctor\DoctorNodeProbeRunner;
use App\Services\Doctor\DoctorReportRunner;
use App\Services\Doctor\DoctorReportSections;

it('keeps one fleet target probe behind a focused service', function (): void {
    $targetProbe = new ReflectionClass(DoctorFleetTargetProbe::class);
    $targetProbeDependencies = collect($targetProbe->getConstructor()?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();

    expect($targetProbeDependencies)
        ->toBe([
            DoctorNodeProbeRunner::class,
            DoctorNodeFamilyResolver::class,
            DoctorIssueFactory::class,
            DoctorReportSections::class,
        ])
        ->not->toContain(DoctorReportRunner::class);
});

it('keeps the public fleet target method as a compatibility delegate', function (): void {
    $runner = new ReflectionClass(DoctorReportRunner::class);
    $runnerDependencies = collect($runner->getConstructor()?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();
    $runnerFile = $runner->getFileName();

    if (! is_string($runnerFile)) {
        throw new LogicException('DoctorReportRunner source file is unavailable.');
    }

    $runnerSource = file_get_contents($runnerFile);

    expect($runnerDependencies)
        ->toContain(DoctorFleetTargetProbe::class)
        ->and($runnerSource)
        ->toBeString()
        ->toContain('return $this->fleetTargetProbe->probe(')
        ->not->toContain('private function nodeProbeFailedReport(')
        ->not->toContain('private function nodeLocalExecutorProbeFailedReport(')
        ->not->toContain('private function remoteShellProbeFailedIssue(');
});
