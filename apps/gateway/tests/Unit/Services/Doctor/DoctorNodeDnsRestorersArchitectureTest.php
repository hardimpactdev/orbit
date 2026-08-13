<?php

declare(strict_types=1);

use App\Services\Dns\DnsmasqReconciler;
use App\Services\Doctor\DoctorDnsProjectionRestorer;
use App\Services\Doctor\DoctorNodeRestorer;
use App\Services\Doctor\DoctorReportRunner;
use App\Services\Nodes\NodesProbe;

it('keeps node and private DNS projection repair behind focused restorers', function (): void {
    expect(class_exists(DoctorNodeRestorer::class))
        ->toBeTrue()
        ->and(class_exists(DoctorDnsProjectionRestorer::class))
        ->toBeTrue();

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

    $source = file_get_contents($runnerFile);

    expect($dependencies)
        ->toContain(DoctorNodeRestorer::class, DoctorDnsProjectionRestorer::class)
        ->not->toContain(NodesProbe::class, DnsmasqReconciler::class)->and($source)->toContain(
            '$this->nodeRestorer->apply($node, $issue)',
            '$this->dnsProjectionRestorer->apply($node, $issue)',
        )
        ->not->toContain(
            'private function applyNodeIssue(',
            'private function applyDnsProjectionIssue(',
            'private function driftEntryFromStoredParts(',
            'private function nodeFromIssue(',
        );
});
