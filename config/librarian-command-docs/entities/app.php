<?php

declare(strict_types=1);

return [
    'required' => [
        'name' => 'string',
        'node' => 'string',
        'environment' => 'string',
        'url' => 'string',
        'path' => 'string',
        'root' => 'string',
        'repository' => 'string|null',
        'php_version' => 'string',
        'adopted' => 'bool',
    ],
    'optional' => [],
];
