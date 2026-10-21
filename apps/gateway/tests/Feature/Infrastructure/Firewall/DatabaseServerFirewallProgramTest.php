<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('verifies database ingress publication, rollback, inspection and Docker startup with fake host commands', function (): void {
    $process = new Process(['python3', '-B', __DIR__.'/database_server_firewall_test.py'], timeout: 10);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
});
