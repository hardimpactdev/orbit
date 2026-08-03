<?php

declare(strict_types=1);

use App\Services\Tools\LegacyOpenClawRuntimeCleanup;
use Tests\TestCase;

require_once __DIR__.'/../../../Support/LegacyOpenClawCleanupHarness.php';

uses(TestCase::class);

it('terminates a simulated detached OpenClaw listener and exits 0 only when residue is gone', function (): void {
    $root = openclaw_cleanup_harness_root();
    openclaw_cleanup_write_stubs($root, unkillableListener: false);
    $script = app(LegacyOpenClawRuntimeCleanup::class)->cleanupScript();

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
    $script = app(LegacyOpenClawRuntimeCleanup::class)->cleanupScript();

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

it('uses privileged sudo ss for both listener enumerations', function (): void {
    $script = app(LegacyOpenClawRuntimeCleanup::class)->cleanupScript();

    expect(substr_count($script, 'sudo ss -lptn'))
        ->toBeGreaterThanOrEqual(1)
        ->and($script)
        ->toContain('listener_pids')
        ->toContain('legacy openclaw cleanup incomplete: port')
        ->toContain('still exists')
        ->toContain('openclaw process still running');
});
