<?php

declare(strict_types=1);

return [
    'node.artifact_enactment_failed' => [
        'family' => 'node',
        'kind' => 'command_handoff',
        'allowed_next_commands' => [
            'doctor --fix --family=node --restore',
        ],
    ],
    'workspace.http_probe_unhealthy' => [
        'family' => null,
        'kind' => 'command_owned',
        'allowed_next_commands' => [
            'orbit workspace:setup',
        ],
    ],
    'proxy.domain_inactive' => [
        'family' => 'proxy',
        'kind' => 'command_handoff',
        'allowed_next_commands' => [
            'app:register',
        ],
    ],
];
