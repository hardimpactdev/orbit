<?php

declare(strict_types=1);

return [
    'enforced_families' => [
        'node',
        'process',
    ],
    'shared' => [
        'authorization_failed',
        'caller_role_not_allowed',
        'gateway_unavailable',
        'local_context_invalid',
        'validation_failed',
    ],
    'products' => [
        'app' => [
            'exec_command_not_executable',
            'exec_command_not_found',
            'exec_container_not_running',
            'exec_docker_unavailable',
            'exec_node_unreachable',
            'exec_unsupported_runtime',
            'not_found',
        ],
        'workspace' => [
            'ambiguous_name',
            'exec_command_not_executable',
            'exec_command_not_found',
            'exec_container_not_running',
            'exec_docker_unavailable',
            'exec_node_unreachable',
            'exec_unsupported_runtime',
            'not_found',
        ],
        'node' => [
            'field_role_incompatible',
            'gateway_api_error',
            'gateway_removal_denied',
            'grant_policy_violation',
            'identity_unknown',
            'incompatible',
            'invalid_role',
            'local_config_write_failed',
            'not_found',
            'provisioning_incomplete',
            'ssh_unreachable',
            'tld_in_use',
            'unsupported_adapter',
            'unsupported_platform',
        ],
        'process' => [
            'event_stream_failed',
            'log_read_failed',
            'name_collision',
            'not_found',
            'runtime_action_failed',
        ],
        'analytics' => [
            'binding_missing',
            'prerequisite_failed',
        ],
    ],
];
