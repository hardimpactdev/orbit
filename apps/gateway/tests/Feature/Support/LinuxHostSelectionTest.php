<?php

declare(strict_types=1);

require_once __DIR__.'/../../Support/LinuxHostCopyFixtures.php';

pest()->group('subprocess');

it('preserves the selected case groups', function (array $selection): void {
    $arguments = ['tests/Fixtures/linux-host-selection.php', '--no-tia', '--colors=never', ...$selection];
    $process = linux_host_run([PHP_BINARY, 'vendor/bin/pest', ...$arguments], base_path());
    expect($process->getExitCode())->toBe(0, $process->getCommandLine()."\n".$process->getOutput().$process->getErrorOutput());
    $this->assertMatchesRegularExpression('/Tests:\s+1 passed/', $process->getOutput());
})->with([
    'ordinary exclusion' => [[]],
    'explicit privileged selection' => [['--group=privileged']],
]);
