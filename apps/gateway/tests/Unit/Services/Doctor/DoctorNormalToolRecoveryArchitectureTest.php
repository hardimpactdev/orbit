<?php

declare(strict_types=1);

use App\Services\Doctor\DoctorReportRunner;
use App\Services\Nodes\NodeConverger;
use App\Services\Tools\ToolsFixer;
use PHPUnit\Framework\Assert;

it('keeps normal tool recovery in NodeConverger', function (): void {
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

    if (! is_string($runnerSource)) {
        throw new LogicException('DoctorReportRunner source cannot be read.');
    }

    $converger = new ReflectionClass(NodeConverger::class);
    $convergerDependencies = collect($converger->getConstructor()?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();

    Assert::assertContains(NodeConverger::class, $runnerDependencies);
    Assert::assertNotContains(ToolsFixer::class, $runnerDependencies);
    Assert::assertStringContainsString('$this->nodeConverger->applyIssues(', $runnerSource);

    foreach ([
        'NodeTool',
        'ToolsFixer',
        'private function applyToolIssue(',
        'private function handleToolAction(',
        "'interactive'",
    ] as $deadRunnerCode) {
        Assert::assertStringNotContainsString($deadRunnerCode, $runnerSource);
    }

    Assert::assertContains(ToolsFixer::class, $convergerDependencies);
    Assert::assertTrue($converger->getMethod('applyToolIssues')->isPrivate());
});
