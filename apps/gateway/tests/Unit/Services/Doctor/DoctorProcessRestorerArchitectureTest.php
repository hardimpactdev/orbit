<?php

declare(strict_types=1);

use App\Services\Doctor\DoctorProcessExtraRuntimeRemover;
use App\Services\Doctor\DoctorProcessRestorer;
use App\Services\Processes\ProcessDockerRuntimeManager;
use App\Services\Processes\ProcessRuntimeDriverRegistry;

it('keeps extra process runtime removal behind one injected service', function (): void {
    $restorer = new ReflectionClass(DoctorProcessRestorer::class);
    $dependencies = collect($restorer->getConstructor()?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();
    $restorerFile = $restorer->getFileName();

    if (! is_string($restorerFile)) {
        throw new LogicException('DoctorProcessRestorer source file is unavailable.');
    }

    $restorerSource = file_get_contents($restorerFile);

    expect(class_exists(DoctorProcessExtraRuntimeRemover::class))
        ->toBeTrue()
        ->and($dependencies)
        ->toContain(DoctorProcessExtraRuntimeRemover::class)
        ->and($restorerSource)
        ->toBeString()
        ->not->toContain(
            'private function removeExtraManagedProcessRuntime(',
            'private function isSafeOrbitSystemdExtraUnit(',
            'private function isSafeOrbitLaunchdExtraUnit(',
            'private function isSafeOrbitManagedRuntimeUnitIdentity(',
            'private function extraRuntimeRemovalAction(',
            'app(ProcessDockerRuntimeManager::class)',
        );
});

it('uses direct dependencies inside the extra process runtime remover', function (): void {
    $remover = new ReflectionClass(DoctorProcessExtraRuntimeRemover::class);
    $dependencies = collect($remover->getConstructor()?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();
    $removerFile = $remover->getFileName();

    if (! is_string($removerFile)) {
        throw new LogicException('DoctorProcessExtraRuntimeRemover source file is unavailable.');
    }

    expect($dependencies)
        ->toBe([
            ProcessDockerRuntimeManager::class,
            ProcessRuntimeDriverRegistry::class,
        ])
        ->and(file_get_contents($removerFile))
        ->not->toContain('app(');
});
