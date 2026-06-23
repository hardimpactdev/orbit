<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('writes a quality gate artifact with required timing and git metadata', function (): void {
    $artifactDir = sys_get_temp_dir().'/orbit-quality-gates-'.bin2hex(random_bytes(6));

    try {
        $process = new Process([
            PHP_BINARY,
            repo_path('bin/quality-gate-write-artifact'),
            '--gate=quality-check',
            '--command=composer quality-check',
            '--mode=check',
            '--started-at=2026-06-23T10:00:00Z',
            '--ended-at=2026-06-23T10:05:30Z',
            '--exit-code=0',
            '--git-branch=quality-gate-timing-artifacts',
            '--git-commit=abc123def456',
            '--subgate=gateway_pest=0',
            '--subgate=docs_lint=0',
            '--subgate=gateway_phpstan=1',
            "--artifact-dir={$artifactDir}",
        ], repo_path());
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

        $artifacts = glob("{$artifactDir}/quality-check-*.json") ?: [];

        expect($artifacts)->toHaveCount(1);

        $artifact = json_decode((string) file_get_contents($artifacts[0]), true, flags: JSON_THROW_ON_ERROR);

        expect($artifact)->toMatchArray([
            'schema_version' => 1,
            'gate' => 'quality-check',
            'command' => 'composer quality-check',
            'mode' => 'check',
            'started_at' => '2026-06-23T10:00:00Z',
            'ended_at' => '2026-06-23T10:05:30Z',
            'duration_seconds' => 330.0,
            'exit_code' => 0,
            'git' => [
                'branch' => 'quality-gate-timing-artifacts',
                'commit' => 'abc123def456',
            ],
            'subgates' => [
                'gateway_pest' => 0,
                'docs_lint' => 0,
                'gateway_phpstan' => 1,
            ],
        ]);
    } finally {
        (new Process(['rm', '-rf', $artifactDir]))->run();
    }
});

it('reports missing quality-check evidence when no artifacts exist', function (): void {
    $artifactDir = sys_get_temp_dir().'/orbit-quality-gates-empty-'.bin2hex(random_bytes(6));
    mkdir($artifactDir, 0700, true);

    try {
        $process = new Process([
            PHP_BINARY,
            repo_path('bin/quality-gate-analyze'),
            "--artifact-dir={$artifactDir}",
        ], repo_path());
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())
            ->toContain('missing evidence')
            ->toContain('quality-check');
    } finally {
        (new Process(['rm', '-rf', $artifactDir]))->run();
    }
});

it('summarizes recent quality gate artifacts without rerunning gates', function (): void {
    $artifactDir = sys_get_temp_dir().'/orbit-quality-gates-recent-'.bin2hex(random_bytes(6));
    mkdir($artifactDir, 0700, true);

    $artifactPath = "{$artifactDir}/quality-check-2026-06-23T100000Z-abc123.json";
    file_put_contents($artifactPath, json_encode([
        'schema_version' => 1,
        'gate' => 'quality-check',
        'command' => 'composer quality-check',
        'mode' => 'check',
        'started_at' => '2026-06-23T10:00:00Z',
        'ended_at' => '2026-06-23T10:05:30Z',
        'duration_seconds' => 330,
        'exit_code' => 0,
        'git' => ['branch' => 'main', 'commit' => 'abc123'],
        'subgates' => ['gateway_pest' => 0],
    ], JSON_THROW_ON_ERROR));

    try {
        $process = new Process([
            PHP_BINARY,
            repo_path('bin/quality-gate-analyze'),
            "--artifact-dir={$artifactDir}",
        ], repo_path());
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())
            ->toContain('quality-check')
            ->toContain('330')
            ->toContain('abc123')
            ->not->toContain('quality-check.sh');
    } finally {
        (new Process(['rm', '-rf', $artifactDir]))->run();
    }
});

it('emits warning-only baseline observations when a run exceeds the local baseline', function (): void {
    $artifactDir = sys_get_temp_dir().'/orbit-quality-gates-baseline-'.bin2hex(random_bytes(6));
    $baselineDir = "{$artifactDir}/baselines";
    mkdir($baselineDir, 0700, true);

    file_put_contents("{$baselineDir}/quality-check.json", json_encode([
        'schema_version' => 1,
        'gate' => 'quality-check',
        'duration_seconds' => 100,
        'updated_at' => '2026-06-23T09:00:00Z',
    ], JSON_THROW_ON_ERROR));

    file_put_contents("{$artifactDir}/quality-check-2026-06-23T100000Z-abc123.json", json_encode([
        'schema_version' => 1,
        'gate' => 'quality-check',
        'command' => 'composer quality-check',
        'mode' => 'check',
        'started_at' => '2026-06-23T10:00:00Z',
        'ended_at' => '2026-06-23T10:05:30Z',
        'duration_seconds' => 330,
        'exit_code' => 0,
        'git' => ['branch' => 'main', 'commit' => 'abc123'],
        'subgates' => ['gateway_pest' => 0],
    ], JSON_THROW_ON_ERROR));

    try {
        $process = new Process([
            PHP_BINARY,
            repo_path('bin/quality-gate-analyze'),
            "--artifact-dir={$artifactDir}",
        ], repo_path());
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())
            ->toContain('warning')
            ->toContain('baseline')
            ->toContain('330')
            ->toContain('100');
    } finally {
        (new Process(['rm', '-rf', $artifactDir]))->run();
    }
});

it('keeps quality-check artifact capture wired into the aggregate gate script', function (): void {
    $composer = json_decode(file_get_contents(repo_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);
    $script = (string) file_get_contents(repo_path('bin/quality-check.sh'));

    expect($composer['scripts'])->toHaveKey('quality-gate:analyze')
        ->and($composer['scripts']['quality-gate:analyze'])->toBe([
            'bin/quality-gate-analyze',
        ])
        ->and($script)
        ->toContain('quality-gate-write-artifact')
        ->toContain('ORBIT_QUALITY_GATES_DIR')
        ->toContain('.orbit/quality-gates');
});

it('documents quality gate artifact and analyzer commands', function (): void {
    $qualityGates = (string) file_get_contents(repo_path('apps/docs/content/testing/quality-gates.md'));

    expect($qualityGates)
        ->toContain('.orbit/quality-gates/')
        ->toContain('composer quality-gate:analyze')
        ->toContain('composer quality-check:fix')
        ->toContain('warning-only');
});
