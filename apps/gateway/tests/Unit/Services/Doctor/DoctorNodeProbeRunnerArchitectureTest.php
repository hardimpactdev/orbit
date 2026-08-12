<?php

declare(strict_types=1);

use App\Services\Doctor\DoctorAppFamilyProbe;
use App\Services\Doctor\DoctorDatabaseConnectionFamilyProbe;
use App\Services\Doctor\DoctorFirewallRuleFamilyProbe;
use App\Services\Doctor\DoctorNodeFamilyProbe;
use App\Services\Doctor\DoctorNodeProbeRunner;
use App\Services\Doctor\DoctorProcessFamilyProbe;
use App\Services\Doctor\DoctorProxyFamilyProbe;
use App\Services\Doctor\DoctorReportRunner;
use App\Services\Doctor\DoctorScheduleFamilyProbe;
use App\Services\Doctor\DoctorToolFamilyProbe;
use App\Services\Doctor\DoctorWorkspaceFamilyProbe;

it('keeps single-node verify coordination behind one service', function (): void {
    $coordinator = new ReflectionClass(DoctorNodeProbeRunner::class);
    $coordinatorDependencies = collect($coordinator->getConstructor()?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();
    $familyProbes = [
        DoctorNodeFamilyProbe::class,
        DoctorAppFamilyProbe::class,
        DoctorWorkspaceFamilyProbe::class,
        DoctorProcessFamilyProbe::class,
        DoctorProxyFamilyProbe::class,
        DoctorFirewallRuleFamilyProbe::class,
        DoctorToolFamilyProbe::class,
        DoctorScheduleFamilyProbe::class,
        DoctorDatabaseConnectionFamilyProbe::class,
    ];

    expect($coordinator->getConstant('FAMILY_ORDER'))
        ->toBe([
            'node',
            'app',
            'workspace',
            'process',
            'proxy',
            'firewall_rule',
            'tool',
            'schedule',
            'database_connection',
        ])
        ->and($coordinatorDependencies)
        ->toContain(...$familyProbes);

    $runner = new ReflectionClass(DoctorReportRunner::class);
    $runnerDependencies = collect($runner->getConstructor()?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();

    expect($runnerDependencies)
        ->toContain(DoctorNodeProbeRunner::class)
        ->not->toContain(...$familyProbes);
});

it('keeps the public probe method as a compatibility delegate', function (): void {
    $runnerFile = new ReflectionClass(DoctorReportRunner::class)->getFileName();

    if (! is_string($runnerFile)) {
        throw new LogicException('DoctorReportRunner source file is unavailable.');
    }

    $runnerSource = file_get_contents($runnerFile);

    expect($runnerSource)
        ->toBeString()
        ->and($runnerSource)
        ->toContain('return $this->nodeProbeRunner->probe(')
        ->not->toContain('private function instancesForNode(');
});
