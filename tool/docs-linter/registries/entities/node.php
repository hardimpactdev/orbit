<?php

declare(strict_types=1);

return [
    'required' => [
        'name' => 'string',
        'role' => 'string',
        'environment' => 'string|null',
        'platform' => 'string',
        'status' => 'string',
    ],
    'optional' => [
        'addresses' => 'object',
        'agent_ide' => 'object',
        'grants' => 'object',
        'tld' => 'string|null',
    ],
];
