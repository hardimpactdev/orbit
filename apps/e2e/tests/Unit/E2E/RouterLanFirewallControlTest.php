<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('preserves scoped resources and exact inventories across the guest control lifecycle and failures', function (): void {
    $root = dirname(__DIR__, 3);
    $directory = temporaryPath('orbit-firewall-control-');
    mkdir($directory, 0o700, true);
    copy($root.'/resources/guest/router-lan-firewall.py', $directory.'/router-lan-firewall.py');
    $process = new Process([
        'python3', '-B', $root.'/tests/Support/router_lan_firewall_cases.py', $directory.'/router-lan-firewall.py',
    ]);
    $process->setTimeout(30);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput().$process->getOutput())
        ->and($process->getErrorOutput())->toContain('Ran 19 tests', 'OK')
        ->and($process->getOutput())->toBe('');
    expect(scandir($directory))->toBe(['.', '..', 'router-lan-firewall.py']);
});
