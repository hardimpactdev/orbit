<?php

declare(strict_types=1);

use App\Services\Tools\LocalToolRunScriptAction;
use App\Services\Tools\LocalToolRunScriptPayload;

it('runs tool scripts in a non-login shell', function (): void {
    $result = new LocalToolRunScriptAction()->run([
        'tool' => 'orbit-proxy',
        'action' => 'probe',
        'script' => <<<'BASH'
            if shopt -q login_shell; then
                exit 41
            fi

            printf 'non-login-shell'
            BASH,
    ]);

    expect($result)
        ->toMatchArray([
            'exit_code' => 0,
            'stdout' => 'non-login-shell',
            'stderr' => '',
        ]);
});

it('accepts and executes gateway php-cli multi-minor probe-php-cli action', function (): void {
    $payload = LocalToolRunScriptPayload::fromArray([
        'tool' => 'php-cli',
        'action' => 'probe-php-cli',
        'script' => "printf '8.5|8.5.8|1|8.5.8|1|1|1|1\\n'",
    ]);

    expect($payload->tool)
        ->toBe('php-cli')
        ->and($payload->action)
        ->toBe('probe-php-cli');

    $result = new LocalToolRunScriptAction()->run([
        'tool' => 'php-cli',
        'action' => 'probe-php-cli',
        'script' => <<<'BASH'
            printf '%s\n' \
              '8.5|8.5.8|1|8.5.8|1|1|1|1' \
              '8.4|8.4.21|1|8.4.21|1|1|1|1' \
              '8.3|8.3.31|1|8.3.31|1|1|1|1'
            BASH,
    ]);

    expect($result['exit_code'])
        ->toBe(0)
        ->and($result['stdout'])
        ->toContain('8.5|8.5.8|1|8.5.8|1|1|1|1')
        ->toContain('8.4|8.4.21|1|8.4.21|1|1|1|1')
        ->toContain('8.3|8.3.31|1|8.3.31|1|1|1|1')
        ->and($result['stderr'])
        ->toBeEmpty();
});

it('rejects unsupported tool run actions including unknown probe variants', function (): void {
    expect(fn () => LocalToolRunScriptPayload::fromArray([
        'tool' => 'php-cli',
        'action' => 'probe-php-cli-extra',
        'script' => 'printf ok',
    ]))
        ->toThrow(\InvalidArgumentException::class, 'Tool run payload action is invalid.');
});

it('accepts and executes gateway install preflight action payloads', function (): void {
    $payload = LocalToolRunScriptPayload::fromArray([
        'tool' => 'hermes',
        'action' => 'preflight',
        'script' => "printf 'preflight-ok'",
    ]);

    expect($payload->tool)
        ->toBe('hermes')
        ->and($payload->action)
        ->toBe('preflight');

    $result = new LocalToolRunScriptAction()->run([
        'tool' => 'hermes',
        'action' => 'preflight',
        'script' => <<<'BASH'
            set -eu
            orbit_runtime_user_id="$(id -u "$(whoami)" 2>/dev/null)" || exit 64
            printf 'preflight-ok'
            BASH,
    ]);

    expect($result)
        ->toMatchArray([
            'exit_code' => 0,
            'stdout' => 'preflight-ok',
            'stderr' => '',
        ]);
});

it('accepts the logs action used by tool:logs remote run payloads', function (): void {
    $payload = LocalToolRunScriptPayload::fromArray([
        'tool' => 'caddy',
        'action' => 'logs',
        'script' => 'docker logs --tail 100 orbit-caddy 2>&1',
    ]);

    expect($payload->tool)
        ->toBe('caddy')
        ->and($payload->action)
        ->toBe('logs');

    $result = new LocalToolRunScriptAction()->run([
        'tool' => 'caddy',
        'action' => 'logs',
        'script' => "printf 'caddy-log-line'",
    ]);

    expect($result)
        ->toMatchArray([
            'exit_code' => 0,
            'stdout' => 'caddy-log-line',
            'stderr' => '',
        ]);
});
