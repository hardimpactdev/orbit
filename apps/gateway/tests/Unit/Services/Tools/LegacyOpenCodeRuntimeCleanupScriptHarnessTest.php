<?php

declare(strict_types=1);

use App\Services\Tools\LegacyOpenCodeRuntimeCleanup;
use Tests\TestCase;

require_once __DIR__.'/../../../Support/LegacyOpenCodeCleanupHarness.php';

uses(TestCase::class);

it('removes Orbit-managed OpenCode home/process residue and exits 0 only when targets are gone', function (): void {
    $root = opencode_cleanup_harness_root();
    opencode_cleanup_write_stubs($root);
    $script = opencode_cleanup_harness_script($root);

    $result = opencode_cleanup_run_script($root, $script);

    expect($result['exit'])
        ->toBe(0, $result['stderr'])
        ->and(trim((string) file_get_contents($root.'/state/processes')))
        ->toBe('')
        ->and(is_dir($root.'/home/.opencode'))
        ->toBeFalse()
        ->and(is_file($root.'/etc/systemd/system/opencode-server.service'))
        ->toBeFalse();
});

it('fails nonzero when a simulated OpenCode process cannot be reaped', function (): void {
    $root = opencode_cleanup_harness_root();
    opencode_cleanup_write_stubs($root, ['unkillable_process' => true]);
    $script = opencode_cleanup_harness_script($root);

    $result = opencode_cleanup_run_script($root, $script);

    expect($result['exit'])
        ->not->toBe(0)->and($result['stderr'])->toContain(
            'opencode process still running',
        )->and(trim((string) file_get_contents($root.'/state/processes')))
        ->not->toBe('');
});

it('fails nonzero when Orbit-managed OpenCode home cannot be removed', function (): void {
    $root = opencode_cleanup_harness_root();
    opencode_cleanup_write_stubs($root, ['undeletable_home' => true]);
    $script = opencode_cleanup_harness_script($root);

    $result = opencode_cleanup_run_script($root, $script);

    expect($result['exit'])
        ->not
        ->toBe(0)
        ->and($result['stderr'])
        ->toContain('still exists')
        ->and(is_dir($root.'/home/.opencode'))
        ->toBeTrue();
});

it('fails nonzero when the opencode-server unit file remains after stop_unit', function (): void {
    $root = opencode_cleanup_harness_root();
    opencode_cleanup_write_stubs($root, ['sticky_unit' => true]);
    $script = opencode_cleanup_harness_script($root);

    $result = opencode_cleanup_run_script($root, $script);

    expect($result['exit'])
        ->not
        ->toBe(0)
        ->and($result['stderr'])
        ->toContain('opencode-server.service unit file still present')
        ->and(is_file($root.'/etc/systemd/system/opencode-server.service'))
        ->toBeTrue();
});

it('ignores inherited ORBIT_LEGACY_OPENCODE target overrides at execution time', function (): void {
    $root = opencode_cleanup_harness_root();
    opencode_cleanup_write_stubs($root);
    $script = opencode_cleanup_harness_script($root);

    $result = opencode_cleanup_run_script($root, $script, [
        'ORBIT_LEGACY_OPENCODE_HOME' => '/tmp/orbit-opencode-evil-home',
        'ORBIT_LEGACY_OPENCODE_USER' => 'root',
    ]);

    expect($result['exit'])
        ->toBe(0, $result['stderr'])
        ->and(is_dir($root.'/home/.opencode'))
        ->toBeFalse()
        ->and(is_dir('/tmp/orbit-opencode-evil-home'))
        ->toBeFalse();
});

it('hard-codes Orbit-managed OpenCode targets without production env override seams', function (): void {
    $script = app(LegacyOpenCodeRuntimeCleanup::class)->cleanupScript();

    expect($script)
        ->toContain("OPENCODE_HOME='/home/agent/.opencode'")
        ->toContain('opencode-server.service')
        ->toContain('legacy opencode cleanup incomplete')
        ->toContain('exit 1')
        ->not->toContain('ORBIT_LEGACY_OPENCODE');
});
