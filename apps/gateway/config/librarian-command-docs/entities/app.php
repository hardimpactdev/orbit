<?php

declare(strict_types=1);

return [
    'required' => [
        'name' => 'string',
        'node' => 'string',
        'url' => 'string',
        'path' => 'string',
        'root' => 'string',
        'repository' => 'string|null',
        'runtime_kind' => 'string',
        'php_version' => 'string',
        'worker_enabled' => 'bool',
        'worker_config' => 'array|null',
        'adopted' => 'bool',
    ],
    'optional' => [],
];
