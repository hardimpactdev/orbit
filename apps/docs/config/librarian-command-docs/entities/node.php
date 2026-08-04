<?php

declare(strict_types=1);

return [
    'required' => [
        'name' => 'string',
        'role' => 'string|null',
        'environment' => 'string|null',
        'platform' => 'string',
        'status' => 'string',
    ],
    'optional' => [
        'addresses' => 'object',
        'grants' => 'object',
        'host' => 'string|null',
        'roles' => 'array',
        'tld' => 'string|null',
    ],
];
