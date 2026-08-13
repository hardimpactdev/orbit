<?php

declare(strict_types=1);

use App\Services\Doctor\DoctorOutcomeReconciler;
use App\Services\Doctor\DoctorReportRunner;

it('keeps Doctor outcome reconciliation behind one injected pure service', function (): void {
    expect(class_exists(DoctorOutcomeReconciler::class))->toBeTrue();

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

    $reconciler = new ReflectionClass(DoctorOutcomeReconciler::class);
    $reconcilerFile = $reconciler->getFileName();

    if (! is_string($reconcilerFile)) {
        throw new LogicException('DoctorOutcomeReconciler source file is unavailable.');
    }

    expect($dependencies)
        ->toContain(DoctorOutcomeReconciler::class)
        ->and(file_get_contents($runnerFile))
        ->not->toContain(
            'private function remainingIssues(',
            'private function annotateRestoreActionsWithRemainingDrift(',
            'private function verifyCompletedDnsToolAction(',
            'private function verifyCompletedNodeDnsAction(',
            'private function verifyCompletedProxyAction(',
            'private function verifyCompletedWebSocketAction(',
            'private function failedProxyAction(',
            'private function actionMatchesRemainingIssue(',
            'private function databaseConnectionIssueResolved(',
            'private function databaseConnectionResolutionKey(',
        )->and(file_get_contents($reconcilerFile))
        ->not->toContain('app(', 'resolve(');
});
