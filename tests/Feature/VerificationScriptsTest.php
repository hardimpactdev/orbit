<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

it('keeps ephemeral e2e on the Incus backend separate from standing live smoke', function (): void {
    $script = base_path('bin/e2e');
    $contents = file_get_contents($script);

    expect($script)->toBeFile()
        ->and(is_executable($script))->toBeTrue()
        ->and($contents)->toContain('ORBIT_E2E_HOST')
        ->and($contents)->toContain('incus launch')
        ->and($contents)->toContain('orbit-e2e')
        ->and($contents)->toContain('Do not run write/destructive E2E against standing live nodes');
});

it('documents the standing live smoke mode', function (): void {
    $script = base_path('bin/live-smoke');
    $contents = file_get_contents($script);

    expect($script)->toBeFile()
        ->and(is_executable($script))->toBeTrue()
        ->and($contents)->toContain('ORBIT_LIVE_GATEWAY_SSH')
        ->and($contents)->toContain('ORBIT_LIVE_GATEWAY_PATH')
        ->and($contents)->toContain('update:all');
});

it('fails command docs lint when warnings are present', function (): void {
    $composer = json_decode(file_get_contents(base_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['docs-lint'])
        ->toContain('--strict')
        ->toContain('--format=agent');
});

it('keeps the aggregate quality gate complete', function (): void {
    $composer = json_decode(file_get_contents(base_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['quality-check'])->toBe([
        '@docs-lint',
        '@analyse',
        '@rector',
        '@format',
        '@test',
    ]);
});

it('lets the ephemeral e2e harness own its timeout', function (): void {
    $composer = json_decode(file_get_contents(base_path('composer.json')) ?: '', associative: true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['test:e2e'])->toBe([
        'Composer\\Config::disableProcessTimeout',
        './bin/e2e',
    ]);
});

it('documents and prepares the reusable blank e2e image', function (): void {
    $script = file_get_contents(base_path('bin/e2e'));

    expect($script)
        ->toContain('--prepare-blank')
        ->toContain('ORBIT_E2E_BLANK_IMAGE')
        ->toContain('ORBIT_E2E_BOOTSTRAP_USER')
        ->toContain('orbit-blank-ubuntu-26.04')
        ->toContain('provisioner')
        ->toContain('ORBIT_E2E_BOOTSTRAP_USER must be a non-orbit Linux username')
        ->toContain('incus publish "$name" --force --reuse --alias "$blank_image"')
        ->toContain('source_image="$1"')
        ->toContain('blank_image="$2"');
});

it('documents and prepares the reusable ready control e2e image', function (): void {
    $script = file_get_contents(base_path('bin/e2e'));

    expect($script)
        ->toContain('--prepare-control')
        ->toContain('--control')
        ->toContain('ORBIT_E2E_CONTROL_IMAGE')
        ->toContain('ORBIT_E2E_CONTROL_USER')
        ->toContain('orbit-ready-control')
        ->toContain('control')
        ->toContain('ORBIT_E2E_CONTROL_USER must be a non-orbit Linux username')
        ->toContain('bin/install-orbit')
        ->toContain("usermod -p '*'")
        ->toContain('--role=control')
        ->toContain('--path=/home/${control_user}/orbit')
        ->toContain('orbit --version');
});

it('rejects orbit as an ephemeral e2e bootstrap or control user', function (): void {
    $script = escapeshellarg(base_path('bin/e2e'));

    $bootstrapUser = Process::run("ORBIT_E2E_BOOTSTRAP_USER=orbit {$script} --preflight");
    $controlUser = Process::run("ORBIT_E2E_CONTROL_USER=orbit {$script} --preflight");

    expect($bootstrapUser->exitCode())->toBe(2)
        ->and($bootstrapUser->errorOutput())->toContain('ORBIT_E2E_BOOTSTRAP_USER must be a non-orbit Linux username')
        ->and($controlUser->exitCode())->toBe(2)
        ->and($controlUser->errorOutput())->toContain('ORBIT_E2E_CONTROL_USER must be a non-orbit Linux username');
});
