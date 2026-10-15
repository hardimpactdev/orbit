<?php

declare(strict_types=1);

it('keeps documentation tooling guidance beside the project', function (): void {
    $guidance = file_get_contents(dirname(__DIR__, 2).'/AGENTS.md');
    $composer = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($guidance)
        ->toBeString()
        ->toContain('repository-root `docs/`')
        ->toContain('console-only')
        ->toContain('composer check');
    expect($composer['scripts']['guidance:check'] ?? null)
        ->toBe('../../bin/guidance-check')
        ->and($composer['scripts']['test'] ?? null)
        ->toBe('../../bin/pest-plain vendor/bin/pest --parallel --processes=$(../../bin/check-processes) --tia --compact --colors=never');
});
