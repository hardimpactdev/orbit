<?php

declare(strict_types=1);

it('clears Rector file-result caches on every project check', function (): void {
    /** @var array{scripts: array{rector: string}} $composer */
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $arguments = explode(' ', $composer['scripts']['rector']);

    expect($arguments)->toContain('--clear-cache');
});
