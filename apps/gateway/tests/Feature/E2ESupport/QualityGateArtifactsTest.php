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

it('runs a wrapped quality gate command and writes timing evidence', function (): void {
    $artifactDir = sys_get_temp_dir().'/orbit-quality-gates-run-'.bin2hex(random_bytes(6));

    try {
        $process = new Process([
            repo_path('bin/quality-gate-run'),
            '--gate=e2e-docker',
            '--command=composer test:e2e:docker',
            "--artifact-dir={$artifactDir}",
            '--',
            PHP_BINARY,
            '-r',
            'fwrite(STDOUT, "wrapped-ok\n");',
        ], repo_path());
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())->toContain('wrapped-ok');

        $artifacts = glob("{$artifactDir}/e2e-docker-*.json") ?: [];

        expect($artifacts)->toHaveCount(1);

        $artifact = json_decode((string) file_get_contents($artifacts[0]), true, flags: JSON_THROW_ON_ERROR);

        expect($artifact)
            ->toMatchArray([
                'schema_version' => 1,
                'gate' => 'e2e-docker',
                'command' => 'composer test:e2e:docker',
                'mode' => 'check',
                'exit_code' => 0,
            ])
            ->and(is_numeric($artifact['duration_seconds']))->toBeTrue()
            ->and($artifact['git']['branch'])->toBeString()
            ->and($artifact['git']['commit'])->toBeString()
            ->and($artifact)->not->toHaveKey('timing_summary');
    } finally {
        (new Process(['rm', '-rf', $artifactDir]))->run();
    }
});

it('captures e2e timing summaries from wrapped command stderr', function (): void {
    $artifactDir = sys_get_temp_dir().'/orbit-quality-gates-e2e-timings-'.bin2hex(random_bytes(6));

    try {
        $process = new Process([
            repo_path('bin/quality-gate-run'),
            '--gate=e2e-docker',
            '--command=composer test:e2e:docker',
            "--artifact-dir={$artifactDir}",
            '--',
            PHP_BINARY,
            '-r',
            'fwrite(STDOUT, "wrapped-out\n"); fwrite(STDERR, "[orbit-e2e] docker start gateway 1.200s\n"); fwrite(STDERR, "[orbit-e2e] docker start gateway 2.400s\n"); fwrite(STDERR, "plain-error\n");',
        ], repo_path());
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())->toContain('wrapped-out')
            ->and($process->getErrorOutput())
            ->toContain('[orbit-e2e] docker start gateway 1.200s')
            ->toContain('[orbit-e2e] docker start gateway 2.400s')
            ->toContain('plain-error');

        $artifacts = glob("{$artifactDir}/e2e-docker-*.json") ?: [];

        expect($artifacts)->toHaveCount(1);

        $artifact = json_decode((string) file_get_contents($artifacts[0]), true, flags: JSON_THROW_ON_ERROR);

        expect($artifact['timing_summary'])
            ->toMatchArray([
                'line_count' => 1,
                'summary_lines' => [
                    'docker/start.gateway n=2 p50=1.2 p95=2.4',
                ],
            ])
            ->and($artifact['timing_summary']['raw_path'])->toStartWith('e2e-timings/e2e-docker-')
            ->and($artifact['timing_summary']['summary_path'])->toStartWith('e2e-timings/e2e-docker-');

        $rawPath = "{$artifactDir}/{$artifact['timing_summary']['raw_path']}";
        $summaryPath = "{$artifactDir}/{$artifact['timing_summary']['summary_path']}";

        expect($rawPath)->toBeFile()
            ->and($summaryPath)->toBeFile()
            ->and(file_get_contents($rawPath))->toContain('[orbit-e2e] docker start gateway 1.200s')
            ->and(file_get_contents($summaryPath))->toContain('docker/start.gateway n=2 p50=1.2 p95=2.4');

        $analyzer = new Process([
            PHP_BINARY,
            repo_path('bin/quality-gate-analyze'),
            "--artifact-dir={$artifactDir}",
            '--gate=e2e-docker',
        ], repo_path());
        $analyzer->run();

        expect($analyzer->getExitCode())->toBe(0, $analyzer->getErrorOutput())
            ->and($analyzer->getOutput())
            ->toContain('timing summary: e2e-timings/e2e-docker-')
            ->toContain('timing phase: docker/start.gateway n=2 p50=1.2 p95=2.4');
    } finally {
        (new Process(['rm', '-rf', $artifactDir]))->run();
    }
});

it('preserves the wrapped quality gate command exit code', function (): void {
    $artifactDir = sys_get_temp_dir().'/orbit-quality-gates-run-fail-'.bin2hex(random_bytes(6));

    try {
        $process = new Process([
            repo_path('bin/quality-gate-run'),
            '--gate=e2e-incus',
            '--command=composer test:e2e:incus',
            "--artifact-dir={$artifactDir}",
            '--',
            PHP_BINARY,
            '-r',
            'exit(7);',
        ], repo_path());
        $process->run();

        expect($process->getExitCode())->toBe(7);

        $artifacts = glob("{$artifactDir}/e2e-incus-*.json") ?: [];

        expect($artifacts)->toHaveCount(1);

        $artifact = json_decode((string) file_get_contents($artifacts[0]), true, flags: JSON_THROW_ON_ERROR);

        expect($artifact)->toMatchArray([
            'gate' => 'e2e-incus',
            'command' => 'composer test:e2e:incus',
            'exit_code' => 7,
        ]);
    } finally {
        (new Process(['rm', '-rf', $artifactDir]))->run();
    }
});

it('rejects quality gate wrapper options that are missing values', function (): void {
    $process = new Process([
        repo_path('bin/quality-gate-run'),
        '--gate',
    ], repo_path());
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('Missing value for --gate.');
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

it('uses warning_threshold_percent from baseline metadata to determine timing warnings', function (): void {
    $artifactDir = sys_get_temp_dir().'/orbit-quality-gates-threshold-custom-'.bin2hex(random_bytes(6));
    $baselineDir = "{$artifactDir}/baselines";
    mkdir($baselineDir, 0700, true);

    file_put_contents("{$baselineDir}/quality-check.json", json_encode([
        'schema_version' => 1,
        'gate' => 'quality-check',
        'duration_seconds' => 100,
        'warning_threshold_percent' => 50,
        'updated_at' => '2026-06-23T09:00:00Z',
    ], JSON_THROW_ON_ERROR));

    file_put_contents("{$artifactDir}/quality-check-2026-06-23T100000Z-abc123.json", json_encode([
        'schema_version' => 1,
        'gate' => 'quality-check',
        'command' => 'composer quality-check',
        'mode' => 'check',
        'started_at' => '2026-06-23T10:00:00Z',
        'ended_at' => '2026-06-23T10:02:20Z',
        'duration_seconds' => 140,
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
            ->toContain('baseline: gate [quality-check] duration 140.0s is within local baseline 100.0s')
            ->not->toContain('warning: gate [quality-check]');
    } finally {
        (new Process(['rm', '-rf', $artifactDir]))->run();
    }
});

it('defaults to a 25 percent warning threshold for legacy baseline files with only duration_seconds', function (): void {
    $artifactDir = sys_get_temp_dir().'/orbit-quality-gates-threshold-legacy-'.bin2hex(random_bytes(6));
    $baselineDir = "{$artifactDir}/baselines";
    mkdir($baselineDir, 0700, true);

    file_put_contents("{$baselineDir}/quality-check.json", json_encode([
        'schema_version' => 1,
        'gate' => 'quality-check',
        'duration_seconds' => 100,
        'updated_at' => '2026-06-23T09:00:00Z',
    ], JSON_THROW_ON_ERROR));

    file_put_contents("{$artifactDir}/quality-check-2026-06-23T100000Z-within.json", json_encode([
        'schema_version' => 1,
        'gate' => 'quality-check',
        'command' => 'composer quality-check',
        'mode' => 'check',
        'started_at' => '2026-06-23T10:00:00Z',
        'ended_at' => '2026-06-23T10:02:00Z',
        'duration_seconds' => 120,
        'exit_code' => 0,
        'git' => ['branch' => 'main', 'commit' => 'within123'],
        'subgates' => ['gateway_pest' => 0],
    ], JSON_THROW_ON_ERROR));

    try {
        $withinProcess = new Process([
            PHP_BINARY,
            repo_path('bin/quality-gate-analyze'),
            "--artifact-dir={$artifactDir}",
        ], repo_path());
        $withinProcess->run();

        expect($withinProcess->getExitCode())->toBe(0, $withinProcess->getErrorOutput())
            ->and($withinProcess->getOutput())
            ->toContain('baseline: gate [quality-check] duration 120.0s is within local baseline 100.0s')
            ->not->toContain('warning: gate [quality-check]');
    } finally {
        (new Process(['rm', '-rf', $artifactDir]))->run();
    }

    $artifactDir = sys_get_temp_dir().'/orbit-quality-gates-threshold-legacy-warn-'.bin2hex(random_bytes(6));
    $baselineDir = "{$artifactDir}/baselines";
    mkdir($baselineDir, 0700, true);

    file_put_contents("{$baselineDir}/quality-check.json", json_encode([
        'schema_version' => 1,
        'gate' => 'quality-check',
        'duration_seconds' => 100,
        'updated_at' => '2026-06-23T09:00:00Z',
    ], JSON_THROW_ON_ERROR));

    file_put_contents("{$artifactDir}/quality-check-2026-06-23T100000Z-exceeds.json", json_encode([
        'schema_version' => 1,
        'gate' => 'quality-check',
        'command' => 'composer quality-check',
        'mode' => 'check',
        'started_at' => '2026-06-23T10:00:00Z',
        'ended_at' => '2026-06-23T10:02:10Z',
        'duration_seconds' => 130,
        'exit_code' => 0,
        'git' => ['branch' => 'main', 'commit' => 'exceeds123'],
        'subgates' => ['gateway_pest' => 0],
    ], JSON_THROW_ON_ERROR));

    try {
        $warningProcess = new Process([
            PHP_BINARY,
            repo_path('bin/quality-gate-analyze'),
            "--artifact-dir={$artifactDir}",
        ], repo_path());
        $warningProcess->run();

        expect($warningProcess->getExitCode())->toBe(0, $warningProcess->getErrorOutput())
            ->and($warningProcess->getOutput())
            ->toContain('warning: gate [quality-check] duration 130.0s exceeds local baseline 100.0s (warning-only)')
            ->toContain('.agents/skills/quality-gate-triage/SKILL.md');
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
            ->toContain('100')
            ->toContain('.agents/skills/quality-gate-triage/SKILL.md');
    } finally {
        (new Process(['rm', '-rf', $artifactDir]))->run();
    }
});

it('keeps final-check exit code zero when only timing baseline warnings are present', function (): void {
    $artifactDir = sys_get_temp_dir().'/orbit-quality-gates-final-timing-only-'.bin2hex(random_bytes(6));
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
            repo_path('bin/quality-gate-final-check'),
            "--artifact-dir={$artifactDir}",
        ], repo_path());
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())
            ->toContain('warning: gate [quality-check] duration 330.0s exceeds local baseline 100.0s (warning-only)')
            ->toContain('.agents/skills/quality-gate-triage/SKILL.md')
            ->toContain('Final check did not rerun quality-check or E2E lanes')
            ->not->toContain('latest gate [quality-check] exited with code');
    } finally {
        (new Process(['rm', '-rf', $artifactDir]))->run();
    }
});

it('final-check skips timing analysis when no quality gate artifacts exist', function (): void {
    $artifactDir = sys_get_temp_dir().'/orbit-quality-gates-final-empty-'.bin2hex(random_bytes(6));
    mkdir($artifactDir, 0700, true);

    try {
        $process = new Process([
            PHP_BINARY,
            repo_path('bin/quality-gate-final-check'),
            "--artifact-dir={$artifactDir}",
        ], repo_path());
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())
            ->toContain('Quality gate final check')
            ->toContain('Timing evidence: missing')
            ->toContain('Timing regression analysis skipped')
            ->toContain('Final check did not rerun quality-check or E2E lanes')
            ->not->toContain('recent run:');
    } finally {
        (new Process(['rm', '-rf', $artifactDir]))->run();
    }
});

it('final-check discovers existing e2e gate artifacts when no explicit gate is passed', function (): void {
    $artifactDir = sys_get_temp_dir().'/orbit-quality-gates-final-e2e-'.bin2hex(random_bytes(6));
    mkdir($artifactDir, 0700, true);

    file_put_contents("{$artifactDir}/e2e-docker-2026-06-23T100000Z-abc123.json", json_encode([
        'schema_version' => 1,
        'gate' => 'e2e-docker',
        'command' => 'composer test:e2e:docker',
        'mode' => 'check',
        'started_at' => '2026-06-23T10:00:00Z',
        'ended_at' => '2026-06-23T10:04:00Z',
        'duration_seconds' => 240,
        'exit_code' => 0,
        'git' => ['branch' => 'quality-gate-e2e-artifacts', 'commit' => 'abc123'],
        'subgates' => [],
    ], JSON_THROW_ON_ERROR));

    try {
        $process = new Process([
            PHP_BINARY,
            repo_path('bin/quality-gate-final-check'),
            "--artifact-dir={$artifactDir}",
        ], repo_path());
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())
            ->toContain('recent run: gate=e2e-docker')
            ->not->toContain('missing evidence: no artifact found for gate [quality-check]')
            ->not->toContain('missing evidence: no artifact found for gate [e2e-incus]')
            ->toContain('Final check did not rerun quality-check or E2E lanes');
    } finally {
        (new Process(['rm', '-rf', $artifactDir]))->run();
    }
});

it('final-check warns when latest timing evidence was captured for a different commit', function (): void {
    $artifactDir = sys_get_temp_dir().'/orbit-quality-gates-final-stale-'.bin2hex(random_bytes(6));
    mkdir($artifactDir, 0700, true);

    $staleCommit = str_repeat('0', 40);

    file_put_contents("{$artifactDir}/e2e-docker-2026-06-23T100000Z-stale.json", json_encode([
        'schema_version' => 1,
        'gate' => 'e2e-docker',
        'command' => 'composer test:e2e:docker',
        'mode' => 'check',
        'started_at' => '2026-06-23T10:00:00Z',
        'ended_at' => '2026-06-23T10:04:00Z',
        'duration_seconds' => 240,
        'exit_code' => 0,
        'git' => ['branch' => 'quality-gate-e2e-artifacts', 'commit' => $staleCommit],
        'subgates' => [],
    ], JSON_THROW_ON_ERROR));

    try {
        $process = new Process([
            PHP_BINARY,
            repo_path('bin/quality-gate-final-check'),
            "--artifact-dir={$artifactDir}",
            '--gate=e2e-docker',
        ], repo_path());
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())
            ->toContain("missing evidence: latest [e2e-docker] artifact commit {$staleCommit} does not match current HEAD")
            ->toContain('Final-check warnings:')
            ->toContain("- missing evidence: latest [e2e-docker] artifact commit {$staleCommit} does not match current HEAD")
            ->toContain('Final check did not rerun quality-check or E2E lanes');
    } finally {
        (new Process(['rm', '-rf', $artifactDir]))->run();
    }
});

it('final-check surfaces analyzer warnings without rerunning quality gates', function (): void {
    $artifactDir = sys_get_temp_dir().'/orbit-quality-gates-final-warning-'.bin2hex(random_bytes(6));
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
        'exit_code' => 1,
        'git' => ['branch' => 'main', 'commit' => 'abc123'],
        'subgates' => ['gateway_pest' => 1],
    ], JSON_THROW_ON_ERROR));

    try {
        $process = new Process([
            PHP_BINARY,
            repo_path('bin/quality-gate-final-check'),
            "--artifact-dir={$artifactDir}",
        ], repo_path());
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and($process->getOutput())
            ->toContain('Analyzer report:')
            ->toContain('recent run: gate=quality-check')
            ->toContain('warning: gate [quality-check] duration 330.0s exceeds local baseline 100.0s (warning-only)')
            ->toContain('.agents/skills/quality-gate-triage/SKILL.md')
            ->toContain('latest gate [quality-check] exited with code 1')
            ->toContain('Final check did not rerun quality-check or E2E lanes');
    } finally {
        (new Process(['rm', '-rf', $artifactDir]))->run();
    }
});

it('keeps final-check wired as an evidence-only composer script', function (): void {
    $composer = json_decode(file_get_contents(repo_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);
    $script = (string) file_get_contents(repo_path('bin/quality-gate-final-check'));
    $implementingFeaturesSkill = (string) file_get_contents(repo_path('.agents/skills/implementing-features/SKILL.md'));

    expect($composer['scripts'])->toHaveKey('quality-gate:final-check')
        ->and($composer['scripts']['quality-gate:final-check'])->toBe([
            'bin/quality-gate-final-check',
        ])
        ->and($script)
        ->toContain('quality-gate-analyze')
        ->not->toContain('quality-check.sh')
        ->not->toContain('vendor/bin/pest')
        ->not->toContain('bin/orbit-gateway-pest')
        ->not->toContain('bin/orbit-e2e-artisan')
        ->not->toContain('test:e2e')
        ->and($implementingFeaturesSkill)
        ->toContain('composer quality-gate:final-check')
        ->toContain('must not rerun Pest')
        ->toContain('timing analysis was skipped')
        ->toContain('first narrow diff')
        ->toContain('broad discovery without a first diff');
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

it('keeps source-prepared e2e artifact capture out of provider provision scripts', function (): void {
    $composer = json_decode(file_get_contents(repo_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);

    expect(repo_path('bin/quality-gate-run'))->toBeFile()
        ->and(is_executable(repo_path('bin/quality-gate-run')))->toBeTrue()
        ->and($composer['scripts']['test:e2e'][1])
        ->toContain('bin/quality-gate-run')
        ->toContain('--gate=e2e')
        ->toContain('--command="composer test:e2e"')
        ->toContain('bin/orbit-e2e-artisan e2e:test')
        ->and($composer['scripts']['test:e2e:docker'][1])
        ->toContain('bin/quality-gate-run')
        ->toContain('--gate=e2e-docker')
        ->toContain('ORBIT_E2E_LANES=docker')
        ->and($composer['scripts']['test:e2e:docker:canary'][1])
        ->toContain('--gate=e2e-docker-canary')
        ->toContain('e2e:test --canary')
        ->and($composer['scripts']['test:e2e:incus'][1])
        ->toContain('--gate=e2e-incus')
        ->toContain('ORBIT_E2E_LANES=incus')
        ->and(implode("\n", $composer['scripts']['test:e2e:provision']))
        ->not->toContain('quality-gate-run')
        ->and(implode("\n", $composer['scripts']['test:e2e:provision:docker']))
        ->not->toContain('quality-gate-run')
        ->and(implode("\n", $composer['scripts']['test:e2e:provision:incus']))
        ->not->toContain('quality-gate-run');
});

it('documents quality gate artifact and analyzer commands', function (): void {
    $qualityGates = (string) file_get_contents(repo_path('apps/docs/content/testing/quality-gates.md'));

    expect($qualityGates)
        ->toContain('.orbit/quality-gates/')
        ->toContain('composer test:e2e')
        ->toContain('e2e-docker')
        ->toContain('e2e-incus')
        ->toContain('composer quality-gate:analyze')
        ->toContain('composer quality-gate:final-check')
        ->toContain('composer quality-check:fix')
        ->toContain('ORBIT_QUALITY_CHECK_MAX_BACKGROUND_JOBS')
        ->toContain('Queue time is reflected in the aggregate gate')
        ->toContain('.orbit/quality-gates/e2e-timings/')
        ->toContain('different Git commit than the current worktree')
        ->toContain('`HEAD`')
        ->toContain('timing phase')
        ->toContain('warning-only');
});

it('promotes the latest successful quality-check artifact into a local baseline file', function (): void {
    $artifactDir = sys_get_temp_dir().'/orbit-quality-gates-baseline-capture-'.bin2hex(random_bytes(6));
    mkdir($artifactDir, 0700, true);

    $olderArtifactPath = "{$artifactDir}/quality-check-2026-06-23T090000Z-older123.json";
    file_put_contents($olderArtifactPath, json_encode([
        'schema_version' => 1,
        'gate' => 'quality-check',
        'command' => 'composer quality-check',
        'mode' => 'check',
        'started_at' => '2026-06-23T09:00:00Z',
        'ended_at' => '2026-06-23T09:04:00Z',
        'duration_seconds' => 240,
        'exit_code' => 0,
        'git' => ['branch' => 'main', 'commit' => 'older123'],
        'subgates' => ['gateway_pest' => 0],
        'subgate_durations' => [
            'core_pest' => 4.2,
            'core_phpstan' => 6.4,
            'core_pint' => 1.0,
            'core_rector' => 2.0,
            'sdk_pest' => 0.5,
            'sdk_phpstan' => 5.0,
            'sdk_pint' => 0.8,
            'sdk_rector' => 1.7,
            'gateway_pest' => 120.0,
            'docs_lint' => 8.0,
        ],
    ], JSON_THROW_ON_ERROR));

    file_put_contents("{$artifactDir}/quality-check-2026-06-23T100300Z-failed789.json", json_encode([
        'schema_version' => 1,
        'gate' => 'quality-check',
        'command' => 'composer quality-check',
        'mode' => 'check',
        'started_at' => '2026-06-23T10:00:00Z',
        'ended_at' => '2026-06-23T10:03:00Z',
        'duration_seconds' => 180,
        'exit_code' => 1,
        'git' => ['branch' => 'main', 'commit' => 'failed789'],
        'subgates' => ['core_pest' => 1, 'sdk_pest' => 1],
        'subgate_durations' => [
            'core_pest' => 0.1,
            'core_phpstan' => -1,
            'sdk_pest' => 0.1,
            'sdk_phpstan' => 'fast',
        ],
    ], JSON_THROW_ON_ERROR));

    $latestArtifactPath = "{$artifactDir}/quality-check-2026-06-23T100530Z-latest456.json";
    file_put_contents($latestArtifactPath, json_encode([
        'schema_version' => 1,
        'gate' => 'quality-check',
        'command' => 'composer quality-check',
        'mode' => 'check',
        'started_at' => '2026-06-23T10:00:00Z',
        'ended_at' => '2026-06-23T10:05:30Z',
        'duration_seconds' => 330,
        'exit_code' => 0,
        'git' => ['branch' => 'quality-gate-baseline-capture', 'commit' => 'latest456'],
        'subgates' => ['gateway_pest' => 0, 'docs_lint' => 0],
        'subgate_durations' => [
            'core_pest' => 3.2,
            'core_phpstan' => 7.0,
            'core_pint' => 1.2,
            'core_rector' => 1.8,
            'sdk_pest' => 0.3,
            'sdk_phpstan' => 4.8,
            'sdk_pint' => 0.9,
            'sdk_rector' => 1.5,
            'gateway_pest' => 245.5,
            'docs_lint' => 12.0,
        ],
    ], JSON_THROW_ON_ERROR));

    try {
        $process = new Process([
            PHP_BINARY,
            repo_path('bin/quality-gate-baseline-capture'),
            "--artifact-dir={$artifactDir}",
        ], repo_path());
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

        $baselinePath = "{$artifactDir}/baselines/quality-check.json";

        expect($baselinePath)->toBeFile();

        $baseline = json_decode((string) file_get_contents($baselinePath), true, flags: JSON_THROW_ON_ERROR);

        expect($baseline)->toMatchArray([
            'schema_version' => 1,
            'gate' => 'quality-check',
            'duration_seconds' => 330.0,
            'warning_threshold_percent' => 25,
            'source_artifact' => basename($latestArtifactPath),
            'updated_at' => '2026-06-23T10:05:30Z',
            'subgate_durations' => [
                'core_pest' => 3.2,
                'core_phpstan' => 7.0,
                'core_pint' => 1.2,
                'core_rector' => 1.8,
                'docs_lint' => 12.0,
                'gateway_pest' => 245.5,
                'sdk_pest' => 0.3,
                'sdk_phpstan' => 4.8,
                'sdk_pint' => 0.9,
                'sdk_rector' => 1.5,
            ],
        ]);

        expect($baseline)->not->toHaveKey('best_subgate_durations');
    } finally {
        (new Process(['rm', '-rf', $artifactDir]))->run();
    }
});

it('refuses to capture a baseline from a failed latest quality-check artifact unless forced', function (): void {
    $artifactDir = sys_get_temp_dir().'/orbit-quality-gates-baseline-refuse-'.bin2hex(random_bytes(6));
    mkdir($artifactDir, 0700, true);

    file_put_contents("{$artifactDir}/quality-check-2026-06-23T100530Z-failed789.json", json_encode([
        'schema_version' => 1,
        'gate' => 'quality-check',
        'command' => 'composer quality-check',
        'mode' => 'check',
        'started_at' => '2026-06-23T10:00:00Z',
        'ended_at' => '2026-06-23T10:05:30Z',
        'duration_seconds' => 330,
        'exit_code' => 1,
        'git' => ['branch' => 'main', 'commit' => 'failed789'],
        'subgates' => ['gateway_pest' => 1],
    ], JSON_THROW_ON_ERROR));

    try {
        $refuseProcess = new Process([
            PHP_BINARY,
            repo_path('bin/quality-gate-baseline-capture'),
            "--artifact-dir={$artifactDir}",
        ], repo_path());
        $refuseProcess->run();

        expect($refuseProcess->getExitCode())->toBe(1, $refuseProcess->getErrorOutput())
            ->and($refuseProcess->getErrorOutput())->toContain('exit_code')
            ->and("{$artifactDir}/baselines/quality-check.json")->not->toBeFile();

        $forceProcess = new Process([
            PHP_BINARY,
            repo_path('bin/quality-gate-baseline-capture'),
            '--force',
            "--artifact-dir={$artifactDir}",
        ], repo_path());
        $forceProcess->run();

        expect($forceProcess->getExitCode())->toBe(0, $forceProcess->getErrorOutput())
            ->and("{$artifactDir}/baselines/quality-check.json")->toBeFile();
    } finally {
        (new Process(['rm', '-rf', $artifactDir]))->run();
    }
});

it('writes per-subgate duration data into quality-check artifacts', function (): void {
    $artifactDir = sys_get_temp_dir().'/orbit-quality-gates-subgate-duration-'.bin2hex(random_bytes(6));

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
            '--git-branch=quality-gate-baseline-capture',
            '--git-commit=profiling123',
            '--subgate=gateway_pest=0',
            '--subgate=docs_lint=0',
            '--subgate-duration=gateway_pest=245.5',
            '--subgate-duration=docs_lint=12.0',
            "--artifact-dir={$artifactDir}",
        ], repo_path());
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

        $artifacts = glob("{$artifactDir}/quality-check-*.json") ?: [];

        expect($artifacts)->toHaveCount(1);

        $artifact = json_decode((string) file_get_contents($artifacts[0]), true, flags: JSON_THROW_ON_ERROR);

        expect($artifact['subgates'])->toMatchArray([
            'docs_lint' => 0,
            'gateway_pest' => 0,
        ])->and($artifact['subgate_durations'])->toMatchArray([
            'docs_lint' => 12.0,
            'gateway_pest' => 245.5,
        ]);
    } finally {
        (new Process(['rm', '-rf', $artifactDir]))->run();
    }
});

it('surfaces slow sub-gate durations from analyzer output', function (): void {
    $artifactDir = sys_get_temp_dir().'/orbit-quality-gates-subgate-analyze-'.bin2hex(random_bytes(6));
    $baselineDir = "{$artifactDir}/baselines";
    mkdir($baselineDir, 0700, true);

    file_put_contents("{$baselineDir}/quality-check.json", json_encode([
        'schema_version' => 1,
        'gate' => 'quality-check',
        'duration_seconds' => 300,
        'warning_threshold_percent' => 25,
        'source_artifact' => 'quality-check-2026-06-23T095000Z-baseline.json',
        'updated_at' => '2026-06-23T09:50:00Z',
        'subgate_durations' => [
            'core_rector' => 0.3,
            'docs_lint' => 12.0,
            'gateway_pest' => 180.0,
        ],
    ], JSON_THROW_ON_ERROR));

    file_put_contents("{$artifactDir}/quality-check-2026-06-23T100530Z-profiling123.json", json_encode([
        'schema_version' => 1,
        'gate' => 'quality-check',
        'command' => 'composer quality-check',
        'mode' => 'check',
        'started_at' => '2026-06-23T10:00:00Z',
        'ended_at' => '2026-06-23T10:05:30Z',
        'duration_seconds' => 330,
        'exit_code' => 0,
        'git' => ['branch' => 'main', 'commit' => 'profiling123'],
        'subgates' => ['gateway_pest' => 0, 'docs_lint' => 0],
        'subgate_durations' => ['gateway_pest' => 245.5, 'docs_lint' => 12.0, 'cli_pest' => 999.9, 'core_rector' => 0.5],
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
            ->toContain('gateway_pest')
            ->toContain('245.5')
            ->toContain('subgate')
            ->toContain('subgate duration: cli_pest=999.9s')
            ->toContain('subgate duration: gateway_pest=245.5s')
            ->toContain('subgate duration: docs_lint=12.0s')
            ->toContain('subgate duration: core_rector=0.5s')
            ->toContain('warning: subgate [quality-check:gateway_pest] duration 245.5s exceeds local baseline 180.0s (warning-only)')
            ->toContain('.agents/skills/quality-gate-triage/SKILL.md')
            ->not->toContain('warning: subgate [quality-check:cli_pest]')
            ->not->toContain('warning: subgate [quality-check:core_rector]')
            ->not->toContain('best subgate duration')
            ->not->toContain('best_subgate_durations');
    } finally {
        (new Process(['rm', '-rf', $artifactDir]))->run();
    }
});

it('keeps baseline capture wired as a composer script', function (): void {
    $composer = json_decode(file_get_contents(repo_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);
    $script = (string) file_get_contents(repo_path('bin/quality-gate-baseline-capture'));
    $analyzerScript = (string) file_get_contents(repo_path('bin/quality-gate-analyze'));

    expect($composer['scripts'])->toHaveKey('quality-gate:baseline-capture')
        ->and($composer['scripts']['quality-gate:baseline-capture'])->toBe([
            'bin/quality-gate-baseline-capture',
        ])
        ->and($script)
        ->toContain('Baseline capture')
        ->not->toContain('quality-gate-analyze')
        ->not->toContain('quality-check.sh')
        ->not->toContain('vendor/bin/pest')
        ->not->toContain('bin/orbit-gateway-pest')
        ->not->toContain('test:e2e')
        ->not->toContain('best_subgate_durations')
        ->and($analyzerScript)
        ->not->toContain('best_subgate_durations');
});

it('documents quality gate baseline capture and subgate profiling', function (): void {
    $qualityGates = (string) file_get_contents(repo_path('apps/docs/content/testing/quality-gates.md'));

    expect($qualityGates)
        ->toContain('composer quality-gate:baseline-capture')
        ->toContain('.orbit/quality-gates/baselines/')
        ->toContain('source_artifact')
        ->toContain('latest quality-gate artifact')
        ->toContain('subgate_durations')
        ->not->toContain('best_subgate_durations')
        ->toContain('warning_threshold_percent');
});
