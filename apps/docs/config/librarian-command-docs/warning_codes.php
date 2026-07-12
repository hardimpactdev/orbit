<?php

declare(strict_types=1);

return [
    'node.artifact_enactment_failed' => [
        'family' => 'node',
        'kind' => 'command_handoff',
        'allowed_next_commands' => [
            'doctor --family=node --restore',
        ],
    ],
    'workspace.http_probe_unhealthy' => [
        'family' => null,
        'kind' => 'command_owned',
        'allowed_next_commands' => [
            'orbit workspace:setup',
        ],
    ],
    'firewall_rule.host_upstream_may_block' => [
        'family' => 'firewall_rule',
        'kind' => 'command_handoff',
        'allowed_next_commands' => [
            'firewall:allow',
        ],
    ],
    'proxy.domain_inactive' => [
        'family' => 'proxy',
        'kind' => 'command_handoff',
        'allowed_next_commands' => [
            'app:register',
        ],
    ],
    'proxy.enactment_deferred' => [
        'family' => 'proxy',
        'kind' => 'command_handoff',
        'allowed_next_commands' => [
            'doctor --family=proxy --restore',
        ],
    ],
    'proxy.docker_runtime_unavailable' => [
        'family' => 'proxy',
        'kind' => 'command_handoff',
        'allowed_next_commands' => [
            'doctor --family=node --restore',
        ],
    ],
    'process.runtime_unit_missing' => [
        'family' => 'process',
        'kind' => 'command_handoff',
        'allowed_next_commands' => [
            'doctor --family=process --restore',
        ],
    ],
    'process.runtime_backend_unavailable' => [
        'family' => 'process',
        'kind' => 'command_handoff',
        'allowed_next_commands' => [
            'doctor --family=process --restore',
        ],
    ],
    'process.tls_certificate_missing' => [
        'family' => 'process',
        'kind' => 'command_handoff',
        'allowed_next_commands' => [
            'doctor --family=process --restore',
        ],
    ],
    'process.runtime_unit_start_failed' => [
        'family' => 'process',
        'kind' => 'command_handoff',
        'allowed_next_commands' => [
            'doctor --family=process --restore',
        ],
    ],
    'process.runtime_unit_restart_failed' => [
        'family' => 'process',
        'kind' => 'command_handoff',
        'allowed_next_commands' => [
            'doctor --family=process --restore',
        ],
    ],
    'workspace.remove_failed' => [
        'family' => 'workspace',
        'kind' => 'command_handoff',
        'allowed_next_commands' => [
            'workspace:remove',
        ],
    ],
    'workspace.teardown_step_failed' => [
        'family' => 'workspace',
        'kind' => 'command_handoff',
        'allowed_next_commands' => [
            'doctor --family=workspace --restore',
        ],
    ],
];
