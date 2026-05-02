<?php

declare(strict_types=1);

it('keeps ephemeral e2e separate from standing live smoke', function (): void {
    $script = base_path('bin/e2e');
    $contents = file_get_contents($script);

    expect($script)->toBeFile()
        ->and(is_executable($script))->toBeTrue()
        ->and($contents)->toContain('Ephemeral E2E is not restored yet')
        ->and($contents)->toContain('composer test:live')
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

    expect($composer['scripts']['docs-lint'])->toContain('--strict');
});
