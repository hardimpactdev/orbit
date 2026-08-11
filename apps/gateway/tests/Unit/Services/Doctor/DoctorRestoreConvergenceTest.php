<?php

declare(strict_types=1);

use App\Data\Doctor\DoctorIssue;
use App\Data\Doctor\DoctorRestoreProbe;
use App\Enums\DriftKind;
use App\Services\Doctor\DoctorIssueFactory;
use App\Services\Doctor\DoctorIssueIdentityResolver;
use App\Services\Doctor\DoctorRestoreConvergence;

it('continues restore passes until no restorable genuine drift remains', function (): void {
    $probes = [
        restoreProbe([issue('tool', 'tool.capability_missing')]),
        restoreProbe([issue('tool', 'tool.config_missing')]),
        restoreProbe([]),
    ];
    $probeIndex = 0;
    $applied = [];

    $result = new DoctorRestoreConvergence()->run(
        probe: function () use (&$probeIndex, $probes): DoctorRestoreProbe {
            $probe = $probes[$probeIndex] ?? restoreProbe([]);
            $probeIndex++;

            return $probe;
        },
        apply: function (array $issues) use (&$applied): array {
            $applied[] = array_map(
                static fn (DoctorIssue $issue): string => $issue->code,
                $issues,
            );

            return array_map(
                static fn (DoctorIssue $issue): array => [
                    'code' => $issue->code,
                    'key' => $issue->key,
                    'mode' => 'restore',
                    'status' => 'completed',
                ],
                $issues,
            );
        },
        isRestorable: static fn (DoctorIssue $issue): bool => $issue->restorable,
    );

    expect($result['stop_reason'])
        ->toBe('converged')
        ->and($result['passes'])
        ->toBe(2)
        ->and($applied)
        ->toBe([
            ['tool.capability_missing'],
            ['tool.config_missing'],
        ])
        ->and($result['probe']->issues)
        ->toBeEmpty();
});

it('stops with no_progress when restorable findings repeat after a pass', function (): void {
    $same = restoreProbe([issue('node', 'node.security.home_perms')]);
    $probes = [$same, $same, $same];
    $probeIndex = 0;

    $result = new DoctorRestoreConvergence()->run(
        probe: function () use (&$probeIndex, $probes): DoctorRestoreProbe {
            $probe = $probes[$probeIndex] ?? $probes[array_key_last($probes)];
            $probeIndex++;

            return $probe;
        },
        apply: static fn (array $issues): array => [[
            'code' => $issues[0]->code,
            'mode' => 'restore',
            'status' => 'completed',
        ]],
        isRestorable: static fn (DoctorIssue $issue): bool => true,
        maxPasses: 8,
    );

    expect($result['stop_reason'])
        ->toBe('no_progress')
        ->and($result['passes'])
        ->toBe(1)
        ->and($result['actions'])
        ->toHaveCount(1);
});

it('stops at max_passes without looping forever', function (): void {
    $oscillating = [
        restoreProbe([issue('tool', 'tool.config_missing')]),
        restoreProbe([issue('tool', 'tool.config_mismatch')]),
    ];
    $probeIndex = 0;

    $result = new DoctorRestoreConvergence()->run(
        probe: function () use (&$probeIndex, $oscillating): DoctorRestoreProbe {
            $probe = $oscillating[$probeIndex % 2];
            $probeIndex++;

            return $probe;
        },
        apply: static fn (array $issues): array => [[
            'code' => $issues[0]->code,
            'mode' => 'restore',
            'status' => 'completed',
        ]],
        isRestorable: static fn (DoctorIssue $issue): bool => true,
        maxPasses: 3,
    );

    expect($result['stop_reason'])
        ->toBe('max_passes')
        ->and($result['passes'])
        ->toBe(3);
});

/**
 */
function issue(string $family, string $code): DoctorIssue
{
    return new DoctorIssueFactory(new DoctorIssueIdentityResolver)->fromArray([
        'family' => $family,
        'code' => $code,
        'key' => $code,
        'node' => 'node-1',
        'kind' => DriftKind::Divergent->value,
        'summary' => $code,
        'detail' => [],
    ]);
}

/**
 * @param  list<DoctorIssue>  $issues
 */
function restoreProbe(array $issues): DoctorRestoreProbe
{
    return new DoctorRestoreProbe(
        report: ['issues' => array_map(
            static fn (DoctorIssue $issue): array => $issue->toArray(),
            $issues,
        )],
        issues: $issues,
    );
}
