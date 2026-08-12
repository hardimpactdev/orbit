<?php

declare(strict_types=1);

use App\Services\Doctor\DnsRuntimeProbe;
use App\Services\Doctor\DoctorDnsRuntimeRestorer;
use App\Services\Doctor\DoctorReportRunner;

it('keeps DNS runtime recovery behind one focused service', function (): void {
    $restorer = new ReflectionClass(DoctorDnsRuntimeRestorer::class);
    $dependencies = collect($restorer->getConstructor()?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();

    expect($dependencies)
        ->toBe([DnsRuntimeProbe::class])
        ->and($restorer->getMethod('apply')->isPublic())
        ->toBeTrue()
        ->and($restorer->getMethod('isRestorable')->isPublic())
        ->toBeTrue();
});

it('keeps DoctorReportRunner as the DNS runtime recovery delegate', function (): void {
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
        ->toContain(DoctorDnsRuntimeRestorer::class)
        ->not->toContain(DnsRuntimeProbe::class);
    expect($runnerSource)
        ->toContain(
            '$this->dnsRuntimeRestorer->isRestorable($issue->key)',
            '$this->dnsRuntimeRestorer->apply($node, $issue)',
        )
        ->not->toContain('private function applyDnsRuntimeIssue(');
});
