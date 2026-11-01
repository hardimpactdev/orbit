<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Feature\Support\LinuxHostCtimeTest;

require_once __DIR__.'/../../Support/LinuxHostCopyFixtures.php';

pest()->group('subprocess');

it('selects the native kernel ctime case for delegated execution', function (): void {
    $method = 'test_removes_legacy_copies_whose_change_time_is_stale';
    linux_host_assert_delegated_selection(new LinuxHostCtimeTest($method), $method);
});

it('preserves the selected case groups', function (array $selection): void {
    $arguments = ['tests/Fixtures/linux-host-selection.php', '--no-tia', '--colors=never', ...$selection];
    $process = new Process([PHP_BINARY, 'vendor/bin/pest', ...$arguments], base_path(), ['PAO_DISABLE' => '1']);
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getCommandLine()."\n".$process->getOutput().$process->getErrorOutput());
    $this->assertMatchesRegularExpression('/Tests:\s+1 passed/', $process->getOutput());
})->with([
    'ordinary exclusion' => [[]],
    'explicit privileged selection' => [['--group=privileged']],
])->skip(fn (): bool => (test()->providedData()[0] ?? null) === [], 'Quarantined: ordinary-selection nested Pest process exceeded 2s in full-suite slice19; restore after its subprocess boot is bounded');
