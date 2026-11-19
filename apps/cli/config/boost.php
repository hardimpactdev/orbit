<?php

declare(strict_types=1);

use Laravel\Boost\Mcp\Tools\LastError;

return [
    'mcp' => [
        'tools' => [
            'exclude' => [
                LastError::class,
            ],
        ],
    ],
];
