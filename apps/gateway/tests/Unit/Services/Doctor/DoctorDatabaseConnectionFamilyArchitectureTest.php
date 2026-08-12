<?php

declare(strict_types=1);

use App\Services\DatabaseConnections\DatabaseConnectionProbe;
use App\Services\Doctor\DoctorDatabaseConnectionFamilyProbe;
use App\Services\Doctor\DoctorNodeProbeRunner;

it('keeps database connection verification behind a focused family service', function (): void {
    $coordinator = new ReflectionClass(DoctorNodeProbeRunner::class);
    $constructor = $coordinator->getConstructor();
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

it('keeps database connection verification dispatch in the node probe coordinator', function (): void {
    $runnerFile = new ReflectionClass(DoctorNodeProbeRunner::class)->getFileName();

    if (! is_string($runnerFile)) {
        throw new LogicException('DoctorNodeProbeRunner source file is unavailable.');
    }

    $runnerSource = file_get_contents($runnerFile);

    expect($runnerSource)
        ->toBeString()
        ->and($runnerSource)
        ->toContain('$this->databaseConnectionFamilyProbe->probe(');
    expect($runnerSource)->not->toContain('$this->databaseConnectionProbe->probe(');
});
