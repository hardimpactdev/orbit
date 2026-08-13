<?php

declare(strict_types=1);

use App\Actions\Apps\EnsureAppProcessRuntimeUnits;
use App\Contracts\SiteCertificateInstaller;
use App\Services\Apps\AppDevelopmentInnerTlsPolicy;
use App\Services\Apps\AppRuntimeContainerRenderer;
use App\Services\Ca\OrbitCaService;
use App\Services\Doctor\DoctorFrankenPhpRuntimeContainerManagers;
use App\Services\Doctor\DoctorManagedFrankenPhpRuntimeRestorer;
use App\Services\Doctor\DoctorProcessExtraRuntimeRemover;
use App\Services\Doctor\DoctorProcessRestorer;
use App\Services\Processes\EnsureFrankenPhpRuntimeProcess;
use App\Services\Processes\ProcessDockerRuntimeManager;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\Runtime\DockerCommandBuilder;
use App\Services\Workspaces\WorkspacePlacement;
use App\Services\Workspaces\WorkspaceRuntimeContainerRenderer;

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

it('keeps managed FrankenPHP runtime repair behind one injected service', function (): void {
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

    expect(class_exists(DoctorManagedFrankenPhpRuntimeRestorer::class))
        ->toBeTrue()
        ->and($dependencies)
        ->toContain(DoctorManagedFrankenPhpRuntimeRestorer::class)
        ->not->toContain(WorkspacePlacement::class);

    expect($restorerSource)
        ->toBeString()
        ->not->toContain(
            'private function restoreMissingFrankenPhpRuntimeProcess(',
            'private function restoreManagedFrankenPhpProcessRuntime(',
            'private function restoreManagedFrankenPhpAppRuntime(',
            'private function restoreManagedFrankenPhpWorkspaceRuntime(',
            'private function refreshManagedFrankenPhpProcessIntent(',
            'private function ensureAppRuntimeTlsMaterial(',
            'private function ensureWorkspaceRuntimeTlsMaterial(',
            'app(',
        );
});

it('uses direct dependencies inside managed FrankenPHP runtime repair', function (): void {
    $restorer = new ReflectionClass(DoctorManagedFrankenPhpRuntimeRestorer::class);
    $dependencies = collect($restorer->getConstructor()?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();
    $restorerFile = $restorer->getFileName();

    if (! is_string($restorerFile)) {
        throw new LogicException('DoctorManagedFrankenPhpRuntimeRestorer source file is unavailable.');
    }

    expect($dependencies)
        ->toBe([
            WorkspacePlacement::class,
            EnsureFrankenPhpRuntimeProcess::class,
            AppRuntimeContainerRenderer::class,
            WorkspaceRuntimeContainerRenderer::class,
            EnsureAppProcessRuntimeUnits::class,
            AppDevelopmentInnerTlsPolicy::class,
            SiteCertificateInstaller::class,
            DoctorFrankenPhpRuntimeContainerManagers::class,
        ])
        ->and(file_get_contents($restorerFile))
        ->not->toContain('app(');
});

it('preserves the Agent-push container manager lane with direct dependencies', function (): void {
    $managers = new ReflectionClass(DoctorFrankenPhpRuntimeContainerManagers::class);
    $dependencies = collect($managers->getConstructor()?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();
    $managersFile = $managers->getFileName();

    if (! is_string($managersFile)) {
        throw new LogicException('DoctorFrankenPhpRuntimeContainerManagers source file is unavailable.');
    }

    expect($dependencies)
        ->toBe([
            DockerCommandBuilder::class,
            OrbitCaService::class,
            AppDevelopmentInnerTlsPolicy::class,
            RemoteLocalExecutor::class,
        ])
        ->and(file_get_contents($managersFile))
        ->not->toContain('app(');
});
