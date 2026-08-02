<?php

declare(strict_types=1);

use App\Tools\OpenClawTool;
use Tests\TestCase;

uses(TestCase::class);

it('declares a process-owned gateway on port 8081 with external supervision', function (): void {
    $tool = new OpenClawTool;
    $command = $tool->relatedProcess()['command'];

    expect($tool->relatedProcess())
        ->toMatchArray([
            'name' => 'openclaw-gateway',
            'runtime' => 'systemd',
            'tool' => 'openclaw',
        ])
        ->and($command)
        ->toContain('OPENCLAW_SUPERVISOR_MODE=external')
        ->toContain('OPENCLAW_SERVICE_REPAIR_POLICY=external')
        ->toContain('OPENCLAW_GATEWAY_TOKEN=')
        ->toContain('gateway.token')
        ->toContain('openclaw gateway run --port 8081 --bind lan')
        ->not->toContain('gateway install')
        ->not->toMatch('/OPENCLAW_GATEWAY_TOKEN=[0-9a-f]{32,}/')
        ->not->toContain('config set gateway.auth.token')->and($tool->capabilities())->toContain(
            'install',
            'remove',
            'update',
            'reconfigure',
            'credentials',
        );
});

it('configures secure gateway intent without printing tokens or installing a native service', function (): void {
    $tool = new OpenClawTool;
    $install = $tool->installScript(['hostname' => 'openclaw.agent']);
    $reconfigure = $tool->reconfigureScript(['hostname' => 'openclaw.agent']);

    expect($install)
        ->toContain('openclaw.ai/install.sh')
        ->toContain('--no-onboard')
        ->toContain('openclaw config set gateway.mode local')
        ->toContain('openclaw config set gateway.port 8081 --strict-json')
        ->toContain('openclaw config set gateway.bind lan')
        ->toContain('openclaw config set gateway.auth.mode token')
        ->toContain('openclaw config unset gateway.auth.token')
        ->toContain('openclaw config set gateway.controlUi.allowedOrigins')
        ->toContain('ORBIT_OPENCLAW_ALLOWED_ORIGINS=')
        ->toContain('https://openclaw.agent')
        ->toContain('--strict-json')
        ->toContain('openssl rand -hex 32')
        ->toContain('gateway.token')
        ->toContain('OPENCLAW_SUPERVISOR_MODE=external')
        ->not->toContain('openclaw gateway install')
        ->not->toContain('openclaw config set gateway.auth.token')
        ->not->toContain('echo "$TOKEN"')
        ->not->toContain('echo "${TOKEN}"')->and($reconfigure)->toContain(
            'openclaw config set gateway.port 8081 --strict-json',
        )->toContain('https://openclaw.agent')
        ->not->toContain('openclaw config set gateway.auth.token')
        ->not->toContain('openclaw gateway install')->and($tool->probeMetadata())->toMatchArray([
            'binary' => 'openclaw',
        ])
        ->not->toHaveKey('repair_commands')
        ->not->toHaveKey('service');
});

it('never clobbers the full OpenClaw config file when converging managed gateway fields', function (): void {
    $tool = new OpenClawTool;
    $scripts = [
        $tool->installScript(['hostname' => 'openclaw.agent']),
        $tool->updateScript(['hostname' => 'openclaw.agent']),
        $tool->reconfigureScript(['hostname' => 'openclaw.agent']),
    ];

    foreach ($scripts as $script) {
        expect($script)
            ->toContain('openclaw config set gateway.mode local')
            ->toContain('openclaw config set gateway.port 8081 --strict-json')
            ->toContain('openclaw config set gateway.bind lan')
            ->toContain('openclaw config set gateway.auth.mode token')
            ->toContain('openclaw config set gateway.controlUi.allowedOrigins')
            ->not->toContain('openclaw config set gateway.auth.token')
            ->not->toContain('cat > "${CONFIG}"')
            ->not->toContain('cat > "${STATE_DIR}/openclaw.json"')
            ->not->toContain('cat > "${HOME}/.openclaw/openclaw.json"')
            ->not->toMatch('/cat\s+>\s+["\']?\$\{?(?:CONFIG|STATE_DIR|HOME)[^"\']*openclaw\.json/')
            ->not->toContain('> "${HOME}/.openclaw/openclaw.json"')
            ->not->toContain('> "${STATE_DIR}/openclaw.json"');
    }
});

it('uses executable shell quoting for allowed-origins without nested single quotes inside bash -lc', function (): void {
    $script = new OpenClawTool()->installScript(['hostname' => 'openclaw.agent']);

    expect($script)
        ->toContain("ORBIT_OPENCLAW_ALLOWED_ORIGINS='[\"https://openclaw.agent\"]'")
        ->toContain(
            'openclaw config set gateway.controlUi.allowedOrigins "${ORBIT_OPENCLAW_ALLOWED_ORIGINS}" --strict-json',
        )
        ->not->toMatch("/bash -lc '.*'\[\\\\?\"https:/");

    // The env assignment is outside bash -lc; the configure -lc body only expands the env var.
    $marker = 'ORBIT_OPENCLAW_ALLOWED_ORIGINS=';
    $envPos = strpos($script, $marker);
    expect($envPos)->not->toBeFalse();

    $configureSegment = substr($script, $envPos);
    $bashLcPos = strpos($configureSegment, "bash -lc '");
    expect($bashLcPos)->not->toBeFalse();

    $bashLcBody = substr($configureSegment, $bashLcPos);
    expect($bashLcBody)
        ->toContain('${ORBIT_OPENCLAW_ALLOWED_ORIGINS}')
        ->not->toContain("'[\"https://openclaw.agent\"]'");
});

it('returns token credentials without embedding a generated secret in the script source', function (): void {
    $script = new OpenClawTool()->credentialsScript(['hostname' => 'openclaw.agent']);

    expect($script)
        ->toContain('https://openclaw.agent')
        ->toContain('auth_mode')
        ->toContain('gateway.token')
        ->not->toMatch('/"[0-9a-f]{64}"/');
});
