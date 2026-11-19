<?php

declare(strict_types=1);

use Laravel\Boost\Mcp\Tools\BrowserLogs;
use Laravel\Boost\Mcp\Tools\GetAbsoluteUrl;
use Laravel\Boost\Mcp\Tools\RecordRule;

return [
    'browser_logs_watcher' => false,

    'mcp' => [
        'tools' => [
            // The Gateway serves no pages, and its rules live in AGENTS.md.
            'exclude' => [
                BrowserLogs::class,
                GetAbsoluteUrl::class,
                RecordRule::class,
            ],
        ],
    ],
];
