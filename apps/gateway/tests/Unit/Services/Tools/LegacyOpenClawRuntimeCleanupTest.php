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

it('ships a removal-only host script that stops units, kills port listeners, and deletes agent state', function (): void {
    $script = app(LegacyOpenClawRuntimeCleanup::class)->cleanupScript();

    expect($script)
        ->toContain('orbit legacy-remove openclaw')
        ->toContain('removal-only migration')
        ->toContain('openclaw-gateway.service')
        ->toContain('openclaw.service')
        ->toContain('sport = :18789')
        ->toContain('kill -TERM')
        ->toContain('kill -KILL')
        ->toContain("pkill -u agent -f '/home/agent/.openclaw/bin/openclaw'")
        ->toContain("pkill -u agent -f 'openclaw gateway'")
        ->toContain('rm -rf "${HOME}/.openclaw"')
        ->not->toContain('install-cli')
        ->not->toContain('install.sh')
        ->not->toContain('gateway run')
        ->not->toContain('tool:install');
});
