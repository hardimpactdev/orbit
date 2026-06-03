<?php

declare(strict_types=1);

use App\Tools\OpenCodeServerTool;

it('installs the server as a supervisor-managed tool program', function (): void {
    $script = (new OpenCodeServerTool)->installScript();

    expect($script)
        ->toContain('program=orbit_tool_opencode_server')
        ->toContain('[program:orbit_tool_opencode_server]')
        ->toContain('sudo supervisorctl reread')
        ->toContain('sudo supervisorctl update "${program}"')
        ->toContain('--hostname 0.0.0.0')
        ->not->toContain('systemctl')
        ->not->toContain('loginctl')
        ->not->toContain('.config/systemd/user');
});

it('uses supervisor for lifecycle scripts and probe metadata', function (): void {
    $tool = new OpenCodeServerTool;
    $metadata = $tool->probeMetadata();
    $repairCommands = is_array($metadata['repair_commands'] ?? null)
        ? $metadata['repair_commands']
        : [];

    expect($tool->removeScript())
        ->toContain('sudo supervisorctl stop "${program}"')
        ->not->toContain('systemctl')
        ->and($tool->updateScript())
        ->toContain('sudo supervisorctl restart "${program}"')
        ->not->toContain('systemctl')
        ->and($tool->reconfigureScript())
        ->toContain('sudo supervisorctl update "${program}"')
        ->not->toContain('systemctl')
        ->and($metadata)
        ->toMatchArray([
            'binary' => 'opencode',
            'supervisor_program' => 'orbit_tool_opencode_server',
            'supervisor_log' => '/var/log/orbit/orbit_tool_opencode_server.log',
        ])
        ->and($repairCommands['lifecycle_running'] ?? null)->toContain('supervisorctl')
        ->and($repairCommands['lifecycle_stopped'] ?? null)->toContain('supervisorctl')
        ->and($repairCommands['lifecycle_restarted'] ?? null)->toContain('supervisorctl');
});
