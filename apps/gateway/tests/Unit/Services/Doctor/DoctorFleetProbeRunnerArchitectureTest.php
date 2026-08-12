<?php

declare(strict_types=1);

use App\Services\Doctor\DoctorFleetNodeProjection;
use App\Services\Doctor\DoctorFleetProbeExecutor;
use App\Services\Doctor\DoctorFleetProbeRunner;
use App\Services\Doctor\DoctorFleetProbeWorker;
use App\Services\Doctor\DoctorFleetProgressReporter;
use App\Services\Doctor\DoctorFleetProgressReportFactory;
use App\Services\Doctor\DoctorFleetTargetProbe;
use App\Services\Doctor\DoctorNodeFamilyResolver;
use App\Services\Doctor\DoctorReportRunner;
use App\Services\Doctor\DoctorReportSections;

it('keeps multi-node verify coordination behind one service', function (): void {
    $coordinator = new ReflectionClass(DoctorFleetProbeRunner::class);
    $coordinatorDependencies = collect($coordinator->getConstructor()?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();

    expect($coordinator->getConstant('BATCH_SIZE'))
        ->toBe(5)
        ->and($coordinatorDependencies)
        ->toBe([
            DoctorNodeFamilyResolver::class,
            DoctorFleetProbeExecutor::class,
            DoctorReportSections::class,
        ])
        ->not->toContain(DoctorReportRunner::class);

    $executorDependencies = collect(
        new ReflectionClass(DoctorFleetProbeExecutor::class)->getConstructor()?->getParameters() ?? [],
    )
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();
    $progressDependencies = collect(
        new ReflectionClass(DoctorFleetProgressReporter::class)->getConstructor()?->getParameters() ?? [],
    )
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();
    $progressReportDependencies = collect(
        new ReflectionClass(DoctorFleetProgressReportFactory::class)->getConstructor()?->getParameters() ?? [],
    )
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();

    expect($executorDependencies)
        ->toBe([
            DoctorNodeFamilyResolver::class,
            DoctorFleetTargetProbe::class,
            DoctorFleetNodeProjection::class,
            DoctorFleetProbeWorker::class,
            DoctorFleetProgressReporter::class,
        ])
        ->and($progressDependencies)
        ->toBe([
            DoctorFleetNodeProjection::class,
            DoctorFleetProgressReportFactory::class,
        ])
        ->and($progressReportDependencies)
        ->toBe([
            DoctorNodeFamilyResolver::class,
            DoctorReportSections::class,
        ]);
});

it('keeps the public fleet methods as compatibility delegates', function (): void {
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
        ->toContain(DoctorFleetProbeRunner::class, DoctorFleetTargetProbe::class)
        ->not->toContain(DoctorFleetNodeProjection::class, DoctorFleetProbeWorker::class)->and(
            $runnerSource,
        )->toBeString()->toContain('return $this->fleetProbeRunner->targetsForFamilies($families);')->toContain(
            'return $this->fleetProbeRunner->probe($families, $key, $onNodeProgress);',
        )->toContain('return $this->fleetTargetProbe->probe(')
        ->not->toContain('private function probeFleetTargets(')
        ->not->toContain('private function fleetProgressReport(');
});
