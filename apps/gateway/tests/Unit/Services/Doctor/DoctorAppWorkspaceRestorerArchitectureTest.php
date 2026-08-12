<?php

declare(strict_types=1);

use App\Services\Apps\AppsFixer;
use App\Services\Doctor\DoctorAppRestorer;
use App\Services\Doctor\DoctorReportRunner;
use App\Services\Doctor\DoctorWorkspaceRestorer;
use App\Services\Workspaces\WorkspacePlacement;

it('keeps app and workspace recovery in their family services', function (): void {
    $appRestorer = new ReflectionClass(DoctorAppRestorer::class);
    $appDependencies = collect($appRestorer->getConstructor()?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();
    $workspaceRestorer = new ReflectionClass(DoctorWorkspaceRestorer::class);
    $workspaceDependencies = collect($workspaceRestorer->getConstructor()?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();

    expect($appDependencies)
        ->toBe([
            AppsFixer::class,
            WorkspacePlacement::class,
        ])
        ->not->toContain(DoctorReportRunner::class)->and($workspaceDependencies)->toBe([
            WorkspacePlacement::class,
        ])
        ->not->toContain(DoctorReportRunner::class);
});

it('keeps DoctorReportRunner as the app and workspace recovery delegate', function (): void {
    $runner = new ReflectionClass(DoctorReportRunner::class);
    $dependencies = collect($runner->getConstructor()?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();
    $runnerFile = $runner->getFileName();

    if (! is_string($runnerFile)) {
        throw new LogicException('DoctorReportRunner source file is unavailable.');
    }

    $runnerSource = file_get_contents($runnerFile);

    if (! is_string($runnerSource)) {
        throw new LogicException('DoctorReportRunner source cannot be read.');
    }

    expect($dependencies)
        ->toContain(DoctorAppRestorer::class, DoctorWorkspaceRestorer::class)
        ->not->toContain(AppsFixer::class, WorkspacePlacement::class);
    expect($runnerSource)
        ->toContain('$this->appRestorer->apply($node, $key, $detail)')
        ->toContain('$this->workspaceRestorer->apply($node, $key, $detail)')
        ->not->toContain(
            'private function applyAppIssue(',
            'private function applyWorkspaceIssue(',
            'private function handleInstanceAction(',
            'private function handleAppConfigExtraAction(',
            'private function handleAppAction(',
            'private function handleWorkspaceAction(',
        );
});
