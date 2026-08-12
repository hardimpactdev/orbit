<?php

declare(strict_types=1);

use App\Services\DatabaseConnections\DatabaseConnectionRestorer;
use App\Services\Doctor\DoctorDatabaseConnectionRestorer;
use App\Services\Doctor\DoctorReportRunner;
use App\Services\Workspaces\WorkspacePlacement;

it('keeps database connection restore handling behind one service', function (): void {
    $restorer = new ReflectionClass(DoctorDatabaseConnectionRestorer::class);
    $dependencies = collect($restorer->getConstructor()?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();

    expect($dependencies)
        ->toBe([
            DatabaseConnectionRestorer::class,
            WorkspacePlacement::class,
        ])
        ->not->toContain(DoctorReportRunner::class);
});

it('keeps DoctorReportRunner as the database connection restore delegate', function (): void {
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

    expect($dependencies)
        ->toContain(DoctorDatabaseConnectionRestorer::class)
        ->not->toContain(DatabaseConnectionRestorer::class)->and($runnerSource)->toBeString()->toContain(
            '$this->databaseConnectionRestorer->apply($key, $detail)',
        )->toContain('$this->databaseConnectionRestorer->issueTargetsWorkspace($detail)')
        ->not->toContain('private function applyDatabaseConnectionIssue(')
        ->not->toContain('private function restoreMissingDatabaseConnectionTarget(');
});
