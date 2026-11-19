<?php

declare(strict_types=1);

use Laravel\Boost\Mcp\Prompts\LaravelCodeSimplifier\LaravelCodeSimplifier;
use Laravel\Boost\Mcp\Prompts\UpgradeAiSdkv1\UpgradeAiSdkV1;
use Laravel\Boost\Mcp\Prompts\UpgradeInertiav3\UpgradeInertiaV3;
use Laravel\Boost\Mcp\Prompts\UpgradeLaravelv13\UpgradeLaravelV13;
use Laravel\Boost\Mcp\Prompts\UpgradeLivewirev4\UpgradeLivewireV4;
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
        // Boost serves the Gateway as tools only: no Boost guidance reaches an agent.
        'prompts' => [
            'exclude' => [
                LaravelCodeSimplifier::class,
                UpgradeAiSdkV1::class,
                UpgradeInertiaV3::class,
                UpgradeLaravelV13::class,
                UpgradeLivewireV4::class,
            ],
        ],
    ],
];
