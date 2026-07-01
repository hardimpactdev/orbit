<?php

declare(strict_types=1);

use App\Tools\OpenCodeCliTool;

it('installs only the server binary because lifecycle belongs to a process', function (): void {
    $script = new OpenCodeCliTool()->installScript();

    expect($script)
        ->toContain('https://opencode.ai/install')
        ->not->toContain('supervisorctl')
        ->not->toContain('/etc/supervisor')
        ->not->toContain('systemctl')
        ->not->toContain('loginctl')
        ->not->toContain('.config/systemd/user');
});

it('keeps lifecycle metadata out of the tool definition', function (): void {
    $tool = new OpenCodeCliTool;
    $metadata = $tool->probeMetadata();

    expect($tool->slug())
        ->toBe('opencode-cli')
        ->and($tool->relatedProcess())
        ->toMatchArray([
            'name' => 'opencode-server',
            'command' => 'opencode serve -a',
            'runtime' => 'systemd',
            'tool' => 'opencode-cli',
        ])
        ->and($tool->removeScript())
        ->toContain('rm -rf "${home}/.opencode"')
        ->not->toContain('supervisorctl')
        ->not->toContain('systemctl')->and($tool->updateScript())->toContain('"${home}/.opencode/bin/opencode" upgrade')
        ->not->toContain('supervisorctl')
        ->not->toContain('systemctl')->and($tool->reconfigureScript())->toContain(
            'tool capability config and credentials',
        )
        ->not->toContain('supervisorctl')
        ->not->toContain('systemctl')->and($metadata)->toMatchArray([
            'binary' => 'opencode',
        ])->and($metadata)
        ->not->toHaveKey('supervisor_program')->and($metadata)
        ->not->toHaveKey('supervisor_log')->and($metadata)
        ->not->toHaveKey('repair_commands');
});
