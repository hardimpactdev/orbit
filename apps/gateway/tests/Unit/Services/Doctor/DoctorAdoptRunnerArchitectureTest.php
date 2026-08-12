<?php

declare(strict_types=1);

use App\Services\DatabaseConnections\DatabaseConnectionAdopter;
use App\Services\Doctor\DoctorAdoptPolicy;
use App\Services\Doctor\DoctorAdoptRunner;
use App\Services\Doctor\DoctorProxyRouteInventory;
use App\Services\Doctor\DoctorReportRunner;
use App\Services\Firewall\FirewallRuleProbe;
use App\Services\Nodes\NodesProbe;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Proxy\ProxyRouteAdopter;
use App\Services\Proxy\ProxyRouteProbe;

it('keeps adopt coordination behind one service', function (): void {
    $coordinator = new ReflectionClass(DoctorAdoptRunner::class);
    $coordinatorDependencies = collect($coordinator->getConstructor()?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();

    expect($coordinatorDependencies)
        ->toBe([
            NodesProbe::class,
            ProxyRouteAdopter::class,
            FirewallRuleProbe::class,
            DatabaseConnectionAdopter::class,
            DoctorAdoptPolicy::class,
        ])
        ->not->toContain(DoctorReportRunner::class);

    $policyDependencies = collect(
        new ReflectionClass(DoctorAdoptPolicy::class)->getConstructor()?->getParameters() ?? [],
    )
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();

    expect($policyDependencies)->toBe([
        ProxyRouteProbe::class,
        DoctorProxyRouteInventory::class,
        NodeRoleAssignments::class,
    ]);
});

it('keeps DoctorReportRunner as the adopt compatibility delegate', function (): void {
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
        ->toContain(DoctorAdoptRunner::class)
        ->and($runnerSource)
        ->toBeString()
        ->toContain('$this->adoptRunner->adopt(')
        ->not->toContain('private function adoptSelectedFamilies(')
        ->not->toContain('private function proxyAdoptSnapshotForScope(');
});
