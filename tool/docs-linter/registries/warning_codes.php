<?php

declare(strict_types=1);

return [
    'node.artifact_enactment_failed' => [
        'family' => 'node',
        'kind' => 'command_handoff',
        'allowed_next_commands' => [
            'doctor --family=node --fix',
        ],
    ],
    'workspace.http_probe_unhealthy' => [
        'family' => null,
        'kind' => 'command_owned',
        'allowed_next_commands' => [
            'orbit workspace:setup',
        ],
    ],
];
