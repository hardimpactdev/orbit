<?php

declare(strict_types=1);

use App\Services\Tools\LegacyOpenClawRuntimeCleanup;
use Tests\TestCase;

uses(TestCase::class);

it('applies only to the openclaw tool slug', function (): void {
    $cleanup = app(LegacyOpenClawRuntimeCleanup::class);

    expect($cleanup->applies(tool: 'openclaw'))
        ->toBeTrue()
        ->and($cleanup->applies(tool: 'hermes'))
        ->toBeFalse()
        ->and($cleanup->applies(tool: 'mailpit'))
        ->toBeFalse();
});

it('ships a removal-only host script that uses privileged ss and verifies absence before success', function (): void {
    $script = app(LegacyOpenClawRuntimeCleanup::class)->cleanupScript();

    expect($script)
        ->toContain('orbit legacy-remove openclaw')
        ->toContain('removal-only migration')
        ->toContain('openclaw-gateway.service')
        ->toContain('openclaw.service')
        ->toContain('sudo ss -lptn')
        ->toContain('listener_pids')
        ->toContain('kill -TERM')
        ->toContain('kill -KILL')
        ->toContain('openclaw_process_present')
        ->toContain('legacy openclaw cleanup incomplete: port')
        ->toContain('still exists')
        ->toContain('openclaw process still running')
        ->not->toContain('install-cli')
        ->not->toContain('install.sh')
        ->not->toContain('gateway run')
        ->not->toContain('tool:install');

    // Verified success checks must appear after the kill/teardown section.
    $killPos = strpos($script, 'kill -KILL');
    $verifyPos = strpos($script, 'legacy openclaw cleanup incomplete');
    expect($killPos)
        ->not->toBeFalse()->and($verifyPos)
        ->not->toBeFalse()->and($verifyPos)->toBeGreaterThan($killPos);
});
