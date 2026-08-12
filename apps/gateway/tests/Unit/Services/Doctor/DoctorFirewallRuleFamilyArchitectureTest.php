<?php

declare(strict_types=1);

use App\Services\Doctor\DoctorFirewallRuleFamilyProbe;
use App\Services\Doctor\DoctorNodeProbeRunner;

it('keeps the firewall-rule verification flow behind a focused service', function (): void {
    $coordinator = new ReflectionClass(DoctorNodeProbeRunner::class);
    $constructor = $coordinator->getConstructor();
    $parameterTypes = collect($constructor?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();

    expect(class_exists(DoctorFirewallRuleFamilyProbe::class))
        ->toBeTrue()
        ->and($parameterTypes)
        ->toContain(DoctorFirewallRuleFamilyProbe::class)
        ->and(
            new ReflectionClass(DoctorFirewallRuleFamilyProbe::class)
                ->getMethod('probe')
                ->isPublic(),
        )
        ->toBeTrue();
});

it('keeps firewall-rule dispatch in the coordinator without family inventory details', function (): void {
    $runnerFile = new ReflectionClass(DoctorNodeProbeRunner::class)->getFileName();

    if (! is_string($runnerFile)) {
        throw new LogicException('DoctorNodeProbeRunner source file is unavailable.');
    }

    $runnerSource = file_get_contents($runnerFile);

    expect($runnerSource)->toBeString();
    expect($runnerSource)->toContain('$this->firewallRuleFamilyProbe->probe(');
    expect($runnerSource)->not->toContain(
        'private function firewallRulesForNode(',
        'private function firewallIssuePayload(',
    );
});
