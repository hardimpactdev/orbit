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
        'runtime' => 'string',
        'runtime_config' => 'object|null',
        'php_version' => 'string',
        'adopted' => 'bool',
    ],
    'optional' => [],
];
