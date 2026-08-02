<?php

declare(strict_types=1);

use App\Tools\HermesTool;
use Tests\TestCase;

uses(TestCase::class);

it('declares a process-owned dashboard on port 8080 with file-loaded basic auth', function (): void {
    $tool = new HermesTool;
    $command = $tool->relatedProcess()['command'];

    expect(HermesTool::WEB_PORT)
        ->toBe(8080)
        ->and(HermesTool::PROCESS_NAME)
        ->toBe('orbit-hermes-dashboard')
        ->and($tool->relatedProcess())
        ->toMatchArray([
            'name' => 'orbit-hermes-dashboard',
            'runtime' => 'systemd',
            'tool' => 'hermes',
        ])
        ->and($command)
        ->toContain('PASSWORD_FILE="/home/agent/.hermes/dashboard.password"')
        ->toContain('SECRET_FILE="/home/agent/.hermes/dashboard.secret"')
        ->toContain('${PASSWORD_FILE}')
        ->toContain('${SECRET_FILE}')
        ->toContain('HERMES_DASHBOARD_BASIC_AUTH_USERNAME=orbit')
        ->toContain('HERMES_DASHBOARD_BASIC_AUTH_PASSWORD=')
        ->toContain('HERMES_DASHBOARD_BASIC_AUTH_SECRET=')
        ->toContain('hermes dashboard --host 0.0.0.0 --port 8080 --no-open')
        ->not->toContain('--port 9119')
        ->not->toContain('--insecure')
        ->not->toMatch('/HERMES_DASHBOARD_BASIC_AUTH_PASSWORD=[^"$\'\\s]{8,}/')
        ->not->toMatch('/HERMES_DASHBOARD_BASIC_AUTH_SECRET=[^"$\'\\s]{8,}/')->and($tool->capabilities())->toContain(
            'install',
            'remove',
            'update',
            'reconfigure',
            'credentials',
        );
});

it('configures managed dashboard credentials and stops only unmanaged dashboards', function (): void {
    $tool = new HermesTool;
    $install = $tool->installScript(['hostname' => 'hermes.agent-1']);
    $reconfigure = $tool->reconfigureScript(['hostname' => 'hermes.agent-1']);
    $update = $tool->updateScript(['hostname' => 'hermes.agent-1']);

    foreach ([$install, $reconfigure, $update] as $script) {
        expect($script)
            ->toContain('dashboard.password')
            ->toContain('dashboard.secret')
            ->toContain('openssl rand')
            ->toContain('chmod 600')
            ->toContain('umask 077')
            ->toContain('dashboard.public_url')
            ->toContain('ORBIT_HERMES_PUBLIC_URL')
            ->toContain('https://hermes.agent-1')
            ->toContain('systemctl is-active --quiet orbit-hermes-dashboard.service')
            ->toContain('hermes dashboard --stop')
            ->not->toContain('systemctl stop orbit-hermes-dashboard')
            ->not->toContain('systemctl stop hermes-dashboard')
            ->not->toContain('<generated-password>')
            ->not->toContain('hermes setup')
            ->not->toContain('echo "$PASSWORD"')
            ->not->toContain('echo "${PASSWORD}"')
            ->not->toContain('echo "$SECRET"')
            ->not->toContain('echo "${SECRET}"');
    }

    expect($install)
        ->toContain('install.sh')
        ->toContain('--skip-setup')
        ->toContain('/usr/local/bin/hermes')
        ->and($tool->probeMetadata())
        ->toMatchArray([
            'binary' => '/usr/local/bin/hermes',
        ])
        ->not->toHaveKey('repair_commands')
        ->not->toHaveKey('service');
});

it('returns username/password credentials without embedding a generated secret in the script source', function (): void {
    $script = new HermesTool()->credentialsScript(['hostname' => 'hermes.agent-1']);

    expect($script)
        ->toContain('https://hermes.agent-1')
        ->toContain('auth_mode')
        ->toContain('basic')
        ->toContain('username')
        ->toContain('orbit')
        ->toContain('dashboard.password')
        ->not->toContain('<generated-password>')
        ->not->toMatch('/"[0-9a-f]{32,}"/')
        ->not->toMatch('/"[A-Za-z0-9+\/=]{32,}"/');
});
