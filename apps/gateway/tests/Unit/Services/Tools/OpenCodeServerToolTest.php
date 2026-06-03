<?php

declare(strict_types=1);

use App\Tools\OpenCodeServerTool;

it('does not require loginctl to install the user service', function (): void {
    $script = (new OpenCodeServerTool)->installScript();

    expect($script)
        ->toContain('if command -v loginctl >/dev/null 2>&1; then')
        ->toContain('sudo loginctl enable-linger "${user}"')
        ->toContain('fi')
        ->not->toContain("\nsudo loginctl enable-linger");
});
