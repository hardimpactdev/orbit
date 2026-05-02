<?php

declare(strict_types=1);

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
        ->toContain('orbit-blank-ubuntu-26.04')
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
        ->toContain('orbit-ready-control')
        ->toContain('bin/install-orbit')
        ->toContain('--role=control')
        ->toContain('orbit --version');
});
