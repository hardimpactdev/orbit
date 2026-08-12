<?php

declare(strict_types=1);

use App\Services\DatabaseConnections\DatabaseConnectionProbe;
use App\Services\Doctor\DoctorDatabaseConnectionFamilyProbe;
use App\Services\Doctor\DoctorReportRunner;

it('keeps database connection verification behind a focused family service', function (): void {
    $runner = new ReflectionClass(DoctorReportRunner::class);
    $constructor = $runner->getConstructor();
    $parameterTypes = collect($constructor?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();

    expect(class_exists(DoctorDatabaseConnectionFamilyProbe::class))->toBeTrue();
    expect($parameterTypes)->toContain(DoctorDatabaseConnectionFamilyProbe::class);
    expect($parameterTypes)->not->toContain(DatabaseConnectionProbe::class);
    expect(
        new ReflectionClass(DoctorDatabaseConnectionFamilyProbe::class)
            ->getMethod('probe')
            ->isPublic(),
    )->toBeTrue();
});

it('removes database connection verification dispatch from the report runner', function (): void {
    $runnerFile = new ReflectionClass(DoctorReportRunner::class)->getFileName();

    if (! is_string($runnerFile)) {
        throw new LogicException('DoctorReportRunner source file is unavailable.');
    }

    $runnerSource = file_get_contents($runnerFile);

    expect($runnerSource)
        ->toBeString()
        ->and($runnerSource)
        ->toContain('$this->databaseConnectionFamilyProbe->probe(');
    expect($runnerSource)->not->toContain('$this->databaseConnectionProbe->probe(');
});
