<?php

declare(strict_types=1);

use App\Services\Tools\LegacyOpenClawRuntimeCleanup;
use Tests\TestCase;

require_once __DIR__.'/../../../Support/LegacyOpenClawCleanupHarness.php';

uses(TestCase::class);

it('terminates a simulated detached OpenClaw listener and exits 0 only when residue is gone', function (): void {
    $root = openclaw_cleanup_harness_root();
    openclaw_cleanup_write_stubs($root, unkillableListener: false);
    $script = openclaw_cleanup_harness_script($root);

    $result = openclaw_cleanup_run_script($root, $script);

    expect($result['exit'])
        ->toBe(0, $result['stderr'])
        ->and(trim((string) file_get_contents($root.'/state/ss.out')))
        ->toBe('')
        ->and(trim((string) file_get_contents($root.'/state/processes')))
        ->toBe('')
        ->and(is_dir($root.'/home/.openclaw'))
        ->toBeFalse();
});

it('fails nonzero when a simulated listener cannot be killed', function (): void {
    $root = openclaw_cleanup_harness_root();
    openclaw_cleanup_write_stubs($root, unkillableListener: true);
    $script = openclaw_cleanup_harness_script($root);

    $result = openclaw_cleanup_run_script($root, $script);

    expect($result['exit'])
        ->not
        ->toBe(0)
        ->and($result['stderr'])
        ->toContain('port 18789 still listening')
        ->and(is_file($root.'/state/ss.out'))
        ->toBeTrue()
        ->and((string) file_get_contents($root.'/state/ss.out'))
        ->toContain('pid=4242');
});

it('ignores inherited ORBIT_LEGACY_OPENCLAW target overrides at execution time', function (): void {
    $root = openclaw_cleanup_harness_root();
    openclaw_cleanup_write_stubs($root, unkillableListener: false);
    $script = openclaw_cleanup_harness_script($root);

    // Evil overrides must not redirect kill/rm/verify away from harness-fixed targets
    // because the production script (and harness rewrite) never reads them.
    $result = openclaw_cleanup_run_script($root, $script, [
        'ORBIT_LEGACY_OPENCLAW_HOME' => '/tmp/orbit-openclaw-evil-home',
        'ORBIT_LEGACY_OPENCLAW_USER' => 'root',
        'ORBIT_LEGACY_OPENCLAW_PORT' => '1',
        'ORBIT_LEGACY_OPENCLAW_KILL_WAIT' => '99',
    ]);

    expect($result['exit'])
        ->toBe(0, $result['stderr'])
        ->and(is_dir($root.'/home/.openclaw'))
        ->toBeFalse()
        ->and(is_dir('/tmp/orbit-openclaw-evil-home'))
        ->toBeFalse();
});

it('uses privileged sudo ss for both listener enumerations', function (): void {
    $script = app(LegacyOpenClawRuntimeCleanup::class)->cleanupScript();

    expect(substr_count($script, 'sudo ss -lptn'))
        ->toBeGreaterThanOrEqual(1)
        ->and($script)
        ->toContain('listener_pids')
        ->toContain('legacy openclaw cleanup incomplete: port')
        ->toContain('still exists')
        ->toContain('openclaw process still running')
        ->not->toContain('ORBIT_LEGACY_OPENCLAW');
});
