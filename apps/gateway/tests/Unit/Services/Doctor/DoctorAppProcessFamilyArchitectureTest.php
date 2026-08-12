<?php

declare(strict_types=1);

use App\Services\Apps\NodeRuntimeContainersInventory;
use App\Services\Doctor\DoctorAppFamilyProbe;
use App\Services\Doctor\DoctorNodeProbeRunner;
use App\Services\Doctor\DoctorProcessFamilyProbe;
use App\Services\Processes\ProcessesProbe;
use App\Services\Processes\ProcessExpectedRuntimeUnits;
use App\Services\Processes\ProcessOwnershipDetail;
use App\Services\Processes\ProcessRuntimeAvailabilityDiff;
use App\Services\Processes\ProcessRuntimeContextDiff;
use App\Services\Processes\ProcessRuntimeContextResolver;
use App\Services\Processes\ProcessRuntimeObserverRegistry;
use App\Services\Processes\ProcessRuntimeObservers\DockerProcessRuntimeObserver;
use App\Services\Processes\ProcessRuntimeObservers\DockerSwarmProcessRuntimeObserver;
use App\Services\Processes\ProcessRuntimeObservers\LaunchdProcessRuntimeObserver;
use App\Services\Processes\ProcessRuntimeObservers\ProcessRuntimeObserver;
use App\Services\Processes\ProcessRuntimeObservers\SystemdProcessRuntimeObserver;
use App\Services\Processes\ProcessRuntimeUnitDiff;
use App\Services\Processes\ProcessRuntimeUnitExtrasDiff;
use App\Services\Processes\ProcessRuntimeUnitLivenessDiff;
use App\Services\Processes\ProcessRuntimeUnitSpecFactory;

it('keeps app and process family probes behind focused services', function (): void {
    $coordinator = new ReflectionClass(DoctorNodeProbeRunner::class);
    $constructor = $coordinator->getConstructor();
    $parameterTypes = collect($constructor?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();

    expect(class_exists(DoctorAppFamilyProbe::class))
        ->toBeTrue()
        ->and(class_exists(DoctorProcessFamilyProbe::class))
        ->toBeTrue()
        ->and($parameterTypes)
        ->toContain(DoctorAppFamilyProbe::class, DoctorProcessFamilyProbe::class);

    foreach ([DoctorAppFamilyProbe::class, DoctorProcessFamilyProbe::class] as $service) {
        $probe = new ReflectionClass($service)->getMethod('probe');

        expect($probe->isPublic())->toBeTrue();
    }
});

it('loads one app instance snapshot and removes hidden runtime service lookups', function (): void {
    $runnerFile = new ReflectionClass(DoctorNodeProbeRunner::class)->getFileName();

    if (! is_string($runnerFile)) {
        throw new LogicException('DoctorNodeProbeRunner source file is unavailable.');
    }

    $runnerSource = file_get_contents($runnerFile);

    expect($runnerSource)
        ->toBeString()
        ->and(substr_count(haystack: (string) $runnerSource, needle: '$this->instancesForNode($node)'))
        ->toBe(1)
        ->and($runnerSource)
        ->not->toContain(
            'private function probeAppFamily(',
            'private function scopedInstances(',
            'private function activePhpRuntimeSlugsForNode(',
            'private function missingFrankenPhpRuntimeProcessIssues(',
            'private function appHasManagedFrankenPhpRuntimeIntent(',
            'app(AppRuntimeContainerRenderer::class)',
            'app(EnsureFrankenPhpRuntimeProcess::class)',
        );

    foreach ([DoctorAppFamilyProbe::class, DoctorProcessFamilyProbe::class] as $service) {
        $serviceFile = new ReflectionClass($service)->getFileName();

        if (! is_string($serviceFile)) {
            throw new LogicException("{$service} source file is unavailable.");
        }

        $serviceSource = file_get_contents($serviceFile);

        expect($serviceSource)
            ->toBeString()
            ->and($serviceSource)
            ->not->toContain('app(');
    }

    $processFamily = new ReflectionClass(DoctorProcessFamilyProbe::class);
    $processFamilyDependencies = collect($processFamily->getConstructor()?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();
    $processesProbeFile = new ReflectionClass(ProcessesProbe::class)->getFileName();

    if (! is_string($processesProbeFile)) {
        throw new LogicException('ProcessesProbe source file is unavailable.');
    }

    expect($processFamilyDependencies)
        ->toContain(NodeRuntimeContainersInventory::class)
        ->and(file_get_contents($processesProbeFile))
        ->not->toContain(
            'introspectNodeRuntimeContainers(',
            'diffNodeRuntimeContainers(',
            'RemoteAppRuntimeContainersProbe',
        );
});

it('keeps runtime context, expected units, and live observation outside ProcessesProbe', function (): void {
    $probe = new ReflectionClass(ProcessesProbe::class);
    $dependencies = collect($probe->getConstructor()?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();
    $sourceFile = $probe->getFileName();

    if (! is_string($sourceFile)) {
        throw new LogicException('ProcessesProbe source file is unavailable.');
    }

    expect($dependencies)
        ->toContain(
            ProcessRuntimeContextResolver::class,
            ProcessRuntimeObserverRegistry::class,
            ProcessRuntimeContextDiff::class,
            ProcessRuntimeUnitDiff::class,
        )
        ->and(class_exists(ProcessRuntimeObserverRegistry::class))
        ->toBeTrue()
        ->and(class_exists(ProcessRuntimeUnitSpecFactory::class))
        ->toBeTrue()
        ->and(class_exists(ProcessRuntimeUnitDiff::class))
        ->toBeTrue()
        ->and(class_exists(ProcessExpectedRuntimeUnits::class))
        ->toBeTrue()
        ->and(file_get_contents($sourceFile))
        ->not->toContain(
            'private function processNode(',
            'private function runtimeContexts(',
            'private function expectedRuntimeUnitSpecs(',
            'private function expectedDockerUnitSpecs(',
            'private function expectedDockerSwarmUnitSpecs(',
            'private function expectedSystemdUnitSpecs(',
            'private function expectedLaunchdUnitSpecs(',
            'private function introspectDocker(',
            'private function introspectDockerSwarm(',
            'private function introspectSystemd(',
            'private function introspectLaunchd(',
            'private function systemdProbeScript(',
            'private function launchdProbeScript(',
            'private function annotateHibernatedDockerUnits(',
            'private function unrenderableRuntimeUnitSnapshot(',
            'private function checkRuntimeBackend(',
            'private function checkRuntimeUnitRenderability(',
            'private function checkRuntimeUnits(',
            'private function checkRuntimeUnitLiveness(',
            'private function checkRestartPolicy(',
            'private function checkRuntimeEnvironment(',
            'private function checkRuntimeUnitExtras(',
            'private function checkRuntimeUnitField(',
        );

    foreach ([
        ProcessesProbe::class,
        ProcessOwnershipDetail::class,
        ProcessRuntimeAvailabilityDiff::class,
        ProcessRuntimeContextDiff::class,
        ProcessRuntimeUnitDiff::class,
        ProcessRuntimeUnitExtrasDiff::class,
        ProcessRuntimeUnitLivenessDiff::class,
    ] as $service) {
        $serviceFile = new ReflectionClass($service)->getFileName();

        if (! is_string($serviceFile)) {
            throw new LogicException("{$service} source file is unavailable.");
        }

        expect(file_get_contents($serviceFile))->not->toContain('app(');
    }

    foreach ([
        DockerProcessRuntimeObserver::class,
        DockerSwarmProcessRuntimeObserver::class,
        LaunchdProcessRuntimeObserver::class,
        SystemdProcessRuntimeObserver::class,
    ] as $observer) {
        $reflection = new ReflectionClass($observer);
        $observerFile = $reflection->getFileName();

        if (! is_string($observerFile)) {
            throw new LogicException("{$observer} source file is unavailable.");
        }

        expect($reflection->implementsInterface(ProcessRuntimeObserver::class))
            ->toBeTrue()
            ->and(file_get_contents($observerFile))
            ->not->toContain('app(');
    }
});
