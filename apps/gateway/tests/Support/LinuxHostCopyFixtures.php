<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\LinuxHost;

function linux_host_temporary_directory(): string
{
    $directory = realpath(sys_get_temp_dir()).'/orbit-linux-host-test-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o700);

    return $directory;
}

/** @param list<string> $arguments */
function linux_host_run(array $arguments, ?string $directory = null): Process
{
    $process = new Process($arguments, $directory);
    $process->run();

    return $process;
}

function linux_host_assert_delegated_selection(TestCase $test, string $expectedMethod): void
{
    $root = dirname(__DIR__, 2);
    $command = LinuxHost::caseCommand($test, $root).' --list-tests';
    $process = new Process(['sh', '-c', $command], $root, timeout: 60);
    $process->run();
    preg_match_all('/^ - .*::(.+?)\.?$/m', $process->getOutput(), $matches);

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
    expect($matches[1])->toBe([$expectedMethod]);
}
