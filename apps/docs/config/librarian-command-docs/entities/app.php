<?php

declare(strict_types=1);

return [
    'required' => [
        'name' => 'string',
        'repository' => 'string|null',
        'runtime' => 'string',
        'runtime_config' => 'object|null',
        'php_version' => 'string',
        'dependency_audit_status' => 'string',
        'dependency_warning_count' => 'int',
        'dependency_danger_count' => 'int',
        'last_dependency_audit_at' => 'string|null',
    ],
    'optional' => [],
];
