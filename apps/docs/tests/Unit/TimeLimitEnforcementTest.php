<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\MediumTests;

pest()->group('subprocess');

it('fails slow tests at the runner level except for the documented medium size', function (): void {
    $root = dirname(__DIR__, 2);

    foreach (['DefaultSlowTest', 'SmallSlowTest', 'MediumSlowTest'] as $fixture) {
        $process = new Process([
            PHP_BINARY, $root.'/vendor/bin/pest',
            $root.'/tests/Fixtures/TimeLimit/'.$fixture.'.php',
            '--no-tia', '--compact', '--colors=never', '--fail-on-empty-test-suite',
        ], $root);
        $process->run();
        $output = $process->getOutput().$process->getErrorOutput();

        if ($fixture === 'MediumSlowTest') {
            expect($process->getExitCode())->toBe(0, $output)
                ->and($output)->toContain('2 passed')->not->toContain('risky');
        } else {
            expect($process->getExitCode())->not->toBe(0, $output)
                ->and($output)->toContain('This test was aborted after 2 seconds', '1 risky', '1 passed');
        }
    }
});

MediumTests::in(__FILE__);
