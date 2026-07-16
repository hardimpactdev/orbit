<?php

declare(strict_types=1);

use App\Services\Tools\LocalToolRunScriptAction;

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
