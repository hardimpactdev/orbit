<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('converges firewall allow through the native planner and end-state check', function (): void {
    // Exercise the real TypeScript behavior, not a second PHP implementation of its rules.
    $process = new Process(['deno', 'test', '--no-lock', 'extensions/behaviors/operations/firewall/firewall_test.ts'], dirname(base_path(), 2).'/swamp');
    $process->setTimeout(120);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
});
