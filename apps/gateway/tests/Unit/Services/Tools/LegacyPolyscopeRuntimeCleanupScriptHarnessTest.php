<?php

declare(strict_types=1);

use App\Services\Tools\LegacyPolyscopeRuntimeCleanup;
use Tests\TestCase;

require_once __DIR__.'/../../../Support/LegacyPolyscopeCleanupHarness.php';

uses(TestCase::class);

it('removes Orbit-managed PolyScope binary/process residue and exits 0 only when targets are gone', function (): void {
    $root = polyscope_cleanup_harness_root();
    polyscope_cleanup_write_stubs($root);
    $script = polyscope_cleanup_harness_script($root);

    $result = polyscope_cleanup_run_script($root, $script);

    expect($result['exit'])
        ->toBe(0, $result['stderr'])
        ->and(trim((string) file_get_contents($root.'/state/processes')))
        ->toBe('')
        ->and(is_file($root.'/home/.local/bin/polyscope-server'))
        ->toBeFalse()
        ->and(is_file($root.'/etc/systemd/system/polyscope-server.service'))
        ->toBeFalse();
});

it('fails nonzero when a simulated PolyScope process cannot be reaped', function (): void {
    $root = polyscope_cleanup_harness_root();
    polyscope_cleanup_write_stubs($root, ['unkillable_process' => true]);
    $script = polyscope_cleanup_harness_script($root);

    $result = polyscope_cleanup_run_script($root, $script);

    expect($result['exit'])
        ->not->toBe(0)->and($result['stderr'])->toContain(
            'polyscope-server process still running',
        )->and(trim((string) file_get_contents($root.'/state/processes')))
        ->not->toBe('');
});

it('fails nonzero when the Orbit-managed PolyScope binary cannot be removed', function (): void {
    $root = polyscope_cleanup_harness_root();
    polyscope_cleanup_write_stubs($root, ['sticky_binary' => true]);
    $script = polyscope_cleanup_harness_script($root);

    $result = polyscope_cleanup_run_script($root, $script);

    expect($result['exit'])
        ->not
        ->toBe(0)
        ->and($result['stderr'])
        ->toContain('still exists')
        ->and(is_file($root.'/home/.local/bin/polyscope-server'))
        ->toBeTrue();
});

it('fails nonzero when the polyscope-server unit file remains after stop_unit', function (): void {
    $root = polyscope_cleanup_harness_root();
    polyscope_cleanup_write_stubs($root, ['sticky_unit' => true]);
    $script = polyscope_cleanup_harness_script($root);

    $result = polyscope_cleanup_run_script($root, $script);

    expect($result['exit'])
        ->not
        ->toBe(0)
        ->and($result['stderr'])
        ->toContain('polyscope-server.service unit file still present')
        ->and(is_file($root.'/etc/systemd/system/polyscope-server.service'))
        ->toBeTrue();
});

it('ignores inherited ORBIT_LEGACY_POLYSCOPE target overrides at execution time', function (): void {
    $root = polyscope_cleanup_harness_root();
    polyscope_cleanup_write_stubs($root);
    $script = polyscope_cleanup_harness_script($root);

    $result = polyscope_cleanup_run_script($root, $script, [
        'ORBIT_LEGACY_POLYSCOPE_BIN' => '/tmp/orbit-polyscope-evil-bin',
        'ORBIT_LEGACY_POLYSCOPE_USER' => 'root',
    ]);

    expect($result['exit'])
        ->toBe(0, $result['stderr'])
        ->and(is_file($root.'/home/.local/bin/polyscope-server'))
        ->toBeFalse()
        ->and(is_file('/tmp/orbit-polyscope-evil-bin'))
        ->toBeFalse();
});

it('hard-codes Orbit-managed PolyScope targets without production env override seams', function (): void {
    $script = app(LegacyPolyscopeRuntimeCleanup::class)->cleanupScript();

    expect($script)
        ->toContain("POLYSCOPE_BIN='/home/agent/.local/bin/polyscope-server'")
        ->toContain('polyscope-server.service')
        ->toContain('legacy polyscope cleanup incomplete')
        ->toContain('exit 1')
        ->not->toContain('ORBIT_LEGACY_POLYSCOPE');
});
