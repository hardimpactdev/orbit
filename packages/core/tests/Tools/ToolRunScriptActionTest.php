<?php

declare(strict_types=1);

use Orbit\Core\Tools\ToolRunScriptAction;

it('exposes the shared gateway-to-CLI tool run-script actions', function (): void {
    expect(ToolRunScriptAction::values())
        ->toBe([
            'install',
            'update',
            'remove',
            'preflight',
            'probe',
            'probe-images',
            'probe-many',
            'probe-php-cli',
            'reconfigure',
            'start',
            'stop',
            'restart',
            'logs',
            'credentials',
        ]);
});

it('accepts every canonical action and rejects unknown actions closed', function (): void {
    foreach (ToolRunScriptAction::values() as $action) {
        expect(ToolRunScriptAction::isAllowed($action))->toBeTrue();
    }

    expect(ToolRunScriptAction::isAllowed('probe-php-cli-extra'))
        ->toBeFalse()
        ->and(ToolRunScriptAction::isAllowed('not-a-real-action'))
        ->toBeFalse();
});
