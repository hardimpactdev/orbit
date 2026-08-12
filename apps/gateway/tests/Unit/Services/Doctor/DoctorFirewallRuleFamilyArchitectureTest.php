<?php

declare(strict_types=1);

use App\Services\Doctor\DoctorFirewallRuleFamilyProbe;
use App\Services\Doctor\DoctorReportRunner;

it('keeps the firewall-rule verification flow behind a focused service', function (): void {
    $runner = new ReflectionClass(DoctorReportRunner::class);
    $constructor = $runner->getConstructor();
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

it('removes firewall-rule verification inventory and issue shaping from the report runner', function (): void {
    $runnerFile = new ReflectionClass(DoctorReportRunner::class)->getFileName();

    if (! is_string($runnerFile)) {
        throw new LogicException('DoctorReportRunner source file is unavailable.');
    }

    $runnerSource = file_get_contents($runnerFile);

    expect($runnerSource)->toBeString();
    expect($runnerSource)->toContain('$this->firewallRuleFamilyProbe->probe(');
    expect($runnerSource)->not->toContain(
        'private function firewallRulesForNode(',
        'private function firewallIssuePayload(',
    );
});
