<?php

declare(strict_types=1);

it('cleans frozen shared Git seeds before the stock Pest run directory is removed', function (): void {
    $arguments = [
        '../../bin/pest-plain', PHP_BINARY, 'vendor/bin/pest',
        'tests/Fixtures/FrozenSharedGitFixtureTest.php',
        '--no-tia', '--fail-on-risky', '--compact', '--colors=never',
    ];
    // A blocking core wait does not install Symfony's temporary stream_select error handler.
    exec('cd '.escapeshellarg(dirname(__DIR__, 3)).' && '
        .implode(' ', array_map(escapeshellarg(...), $arguments)).' 2>&1', $output, $exitCode);

    expect($exitCode)->toBe(0, implode("\n", $output));
});
