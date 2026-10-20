<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\LinuxHost;

function linux_host_assert_delegated_selection(TestCase $test, string $expectedMethod): void
{
    $root = dirname(__DIR__, 2);
    $command = LinuxHost::caseCommand($test, $root).' --list-tests';
    $process = new Process(['sh', '-c', $command], $root, timeout: 60);
    $process->run();
    preg_match_all('/^ - .*::(.+)\.$/m', $process->getOutput(), $matches);

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
    expect($matches[1])->toBe([$expectedMethod]);
}

it('selects its delegated case', function (): void {
    linux_host_assert_delegated_selection($this, '__pest_evaluable_it_selects_its_delegated_case');
});

// The ordinary filter also matches this description, so dropping the XML exclusion broadens its selection.
it('selects its delegated case when privileged', function (): void {
    linux_host_assert_delegated_selection($this, '__pest_evaluable_it_selects_its_delegated_case_when_privileged');
})->group('privileged');
