<?php

declare(strict_types=1);

use App\Services\Doctor\DoctorGatewayScheduleRestorer;
use App\Services\Doctor\DoctorIssueNodeResolver;
use App\Services\Doctor\DoctorReportRunner;
use App\Services\Doctor\DoctorScheduleNodeResolver;
use App\Services\Doctor\DoctorScheduleRestorer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Schedules\SchedulesFixer;

it('keeps schedule restore handling behind one service', function (): void {
    $restorer = new ReflectionClass(DoctorScheduleRestorer::class);
    $dependencies = collect($restorer->getConstructor()?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();

    expect($dependencies)->toBe([
        DoctorGatewayScheduleRestorer::class,
        SchedulesFixer::class,
        DoctorScheduleNodeResolver::class,
    ]);
    expect(in_array(DoctorReportRunner::class, $dependencies, strict: true))->toBeFalse();
});

it('keeps gateway schedule repair behind its focused service', function (): void {
    $restorer = new ReflectionClass(DoctorGatewayScheduleRestorer::class);
    $dependencies = collect($restorer->getConstructor()?->getParameters() ?? [])
        ->map(static fn (ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString())
        ->filter()
        ->values()
        ->all();

    expect($dependencies)->toBe([
        SchedulesFixer::class,
        NodeRoleAssignments::class,
        DoctorIssueNodeResolver::class,
    ]);
    expect(in_array(DoctorReportRunner::class, $dependencies, strict: true))->toBeFalse();
});

it('keeps DoctorReportRunner as the schedule restore delegate', function (): void {
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

    expect($dependencies)->toContain(DoctorScheduleRestorer::class);
    expect(in_array(SchedulesFixer::class, $dependencies, strict: true))->toBeFalse();
    expect(in_array(DoctorScheduleNodeResolver::class, $dependencies, strict: true))->toBeFalse();
    expect(in_array(NodeRoleAssignments::class, $dependencies, strict: true))->toBeFalse();
    expect(str_contains($runnerSource, '$this->scheduleRestorer->apply($node, $key, $detail, $issue)'))->toBeTrue();
    expect(str_contains($runnerSource, 'private function applyScheduleIssue('))->toBeFalse();
    expect(str_contains($runnerSource, 'private function handleScheduleAction('))->toBeFalse();
    expect(str_contains($runnerSource, 'private function gatewayNode('))->toBeFalse();
});
