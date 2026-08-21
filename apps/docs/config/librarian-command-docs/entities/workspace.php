<?php

declare(strict_types=1);

return [
    'required' => [
        'name' => 'string',
        'app' => 'string',
        'instance' => 'string',
        'node' => 'string|null',
        'url' => 'string',
    ],
    'optional' => [
        'adopted' => 'bool',
        'lifecycle_status' => 'string',
        'path' => 'string',
        'php_inherited' => 'bool',
        'php_version' => 'string|null',
    ],
];
