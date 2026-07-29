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
        ->toBe('');
});

it('rejects unsupported tool run actions including unknown probe variants', function (): void {
    expect(fn () => LocalToolRunScriptPayload::fromArray([
        'tool' => 'php-cli',
        'action' => 'probe-php-cli-extra',
        'script' => 'printf ok',
    ]))
        ->toThrow(\InvalidArgumentException::class, 'Tool run payload action is invalid.');
});
