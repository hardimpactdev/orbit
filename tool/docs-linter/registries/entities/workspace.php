<?php

declare(strict_types=1);

return [
    'required' => [
        'name' => 'string',
        'app' => 'string',
        'node' => 'string|object',
        'url' => 'string',
    ],
    'optional' => [
        'adopted' => 'bool',
        'agent_ide' => 'object',
        'base' => 'string',
        'branch' => 'string|null',
        'inherited_processes' => 'array',
        'latest_setup_run' => 'object|null',
        'lifecycle_status' => 'string',
        'path' => 'string',
        'php_inherited' => 'bool',
        'php_version' => 'string',
        'route' => 'object|null',
        'runtime_expectations' => 'object',
    ],
];
