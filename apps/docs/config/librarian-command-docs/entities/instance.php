<?php

declare(strict_types=1);

return [
    'required' => [
        'name' => 'string',
        'project' => 'string',
    ],
    'optional' => [
        'adopted' => 'bool',
        'node' => 'string|object',
        'path' => 'string',
        'root' => 'string',
        'url' => 'string',
        'worker_config' => 'object|null',
        'worker_enabled' => 'bool',
    ],
];
