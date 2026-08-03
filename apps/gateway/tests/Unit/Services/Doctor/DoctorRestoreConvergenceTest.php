<?php

declare(strict_types=1);

use App\Services\Doctor\DoctorRestoreConvergence;

it('continues restore passes until no restorable genuine drift remains', function (): void {
    $probes = [
        [
            'issues' => [
                issue('tool', 'tool.capability_missing'),
            ],
        ],
        [
            'issues' => [
                issue('tool', 'tool.config_missing'),
            ],
        ],
        [
            'issues' => [],
        ],
    ];
    $probeIndex = 0;
    $applied = [];

    $result = new DoctorRestoreConvergence()->run(
        probe: function () use (&$probeIndex, $probes): array {
            $probe = $probes[$probeIndex] ?? ['issues' => []];
            $probeIndex++;

            return $probe;
        },
        apply: function (array $issues) use (&$applied): array {
            $applied[] = array_map(
                static fn (array $issue): string => (string) $issue['code'],
                $issues,
            );

            return array_map(
                static fn (array $issue): array => [
                    'code' => $issue['code'],
                    'key' => $issue['key'],
                    'mode' => 'restore',
                    'status' => 'completed',
                ],
                $issues,
            );
        },
        isRestorable: static fn (array $issue): bool => ($issue['restorable'] ?? false) === true,
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
        ->and($result['probe']['issues'] ?? null)
        ->toBe([]);
});

it('stops with no_progress when restorable findings repeat after a pass', function (): void {
    $same = [
        'issues' => [
            issue('node', 'node.security.home_perms'),
        ],
    ];
    $probes = [$same, $same, $same];
    $probeIndex = 0;

    $result = new DoctorRestoreConvergence()->run(
        probe: function () use (&$probeIndex, $probes): array {
            $probe = $probes[$probeIndex] ?? $probes[array_key_last($probes)];
            $probeIndex++;

            return $probe;
        },
        apply: static fn (array $issues): array => [[
            'code' => $issues[0]['code'],
            'mode' => 'restore',
            'status' => 'completed',
        ]],
        isRestorable: static fn (array $issue): bool => true,
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
        [
            'issues' => [issue('tool', 'tool.config_missing')],
        ],
        [
            'issues' => [issue('tool', 'tool.config_mismatch')],
        ],
    ];
    $probeIndex = 0;

    $result = new DoctorRestoreConvergence()->run(
        probe: function () use (&$probeIndex, $oscillating): array {
            $probe = $oscillating[$probeIndex % 2];
            $probeIndex++;

            return $probe;
        },
        apply: static fn (array $issues): array => [[
            'code' => $issues[0]['code'],
            'mode' => 'restore',
            'status' => 'completed',
        ]],
        isRestorable: static fn (array $issue): bool => true,
        maxPasses: 3,
    );

    expect($result['stop_reason'])
        ->toBe('max_passes')
        ->and($result['passes'])
        ->toBe(3);
});

/**
 * @return array<string, mixed>
 */
function issue(string $family, string $code): array
{
    return [
        'family' => $family,
        'code' => $code,
        'key' => $code,
        'node' => 'node-1',
        'restorable' => true,
        'detail' => [],
    ];
}
