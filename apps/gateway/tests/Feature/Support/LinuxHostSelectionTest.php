<?php

declare(strict_types=1);

use Tests\Feature\Support\LinuxHostCtimeTest;

require_once __DIR__.'/../../Support/LinuxHostCopyFixtures.php';

pest()->group('subprocess');

it('selects the native kernel ctime case for delegated execution', function (): void {
    $method = 'test_removes_legacy_copies_whose_change_time_is_stale';
    linux_host_assert_delegated_selection(new LinuxHostCtimeTest($method), $method);
});

it('preserves the selected case groups', function (array $selection): void {
    $arguments = ['tests/Fixtures/linux-host-selection.php', '--no-tia', '--colors=never', ...$selection];
    $process = linux_host_run([PHP_BINARY, 'vendor/bin/pest', ...$arguments], base_path());
    expect($process->getExitCode())->toBe(0, $process->getCommandLine()."\n".$process->getOutput().$process->getErrorOutput());
    $summary = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
    expect($summary)->toMatchArray(['tool' => 'pest', 'result' => 'passed', 'tests' => 1, 'passed' => 1]);
})->with([
    'ordinary exclusion' => [[]],
    'explicit privileged selection' => [['--group=privileged']],
]);
