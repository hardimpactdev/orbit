<?php

declare(strict_types=1);

use Laravel\Boost\Mcp\Tools\LastError;

// AGENTS.md is hand-written and holds every CLI rule. Boost serves only its MCP server, and records no rule files.
return [
    'mcp' => [
        'tools' => [
            'exclude' => [
                LastError::class,
            ],
        ],
    ],
    'rules' => [
        'enabled' => false,
    ],
];
