<?php

declare(strict_types=1);

use App\Services\Doctor\DoctorFirewallRuleRestorer;
use App\Services\Doctor\DoctorReportRunner;
use App\Services\Firewall\FirewallRuleFixer;

it('keeps firewall rule recovery behind one family service', function (): void {
    $restorer = new ReflectionClass(DoctorFirewallRuleRestorer::class);
    $dependencies = collect($restorer->getConstructor()?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();

    expect($dependencies)->toBe([FirewallRuleFixer::class])->and($restorer->getMethod('apply')->isPublic())->toBeTrue();
});

it('keeps DoctorReportRunner as the firewall rule recovery delegate', function (): void {
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
        ->toContain(DoctorFirewallRuleRestorer::class)
        ->not->toContain(FirewallRuleFixer::class);
    expect($runnerSource)
        ->toContain('$this->firewallRuleRestorer->apply($node, $key, $detail)')
        ->not->toContain(
            'private function applyFirewallIssue(',
            'private function handleFirewallAction(',
        );
});
