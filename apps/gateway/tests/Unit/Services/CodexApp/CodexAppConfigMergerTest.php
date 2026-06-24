<?php

declare(strict_types=1);

use App\Services\CodexApp\CodexAppConfigMerger;
use Tests\TestCase;

uses(TestCase::class);

describe(CodexAppConfigMerger::class, function (): void {
    it('adds and updates project entries while preserving unrelated config', function (): void {
        $config = [
            'version' => 1,
            'theme' => 'system',
            'remoteConnections' => [
                [
                    'sshAlias' => 'existing',
                    'projects' => [
                        [
                            'remotePath' => '/existing',
                            'label' => 'Existing',
                        ],
                    ],
                ],
            ],
        ];

        $merger = app(CodexAppConfigMerger::class);
        $added = $merger->addProject($config, 'Docs', 'app-node', '/home/orbit/apps/docs');
        $updated = $merger->addProject($added, 'Docs', 'app-node', '/home/orbit/apps/docs-api');

        expect($updated['theme'])
            ->toBe('system')
            ->and($updated['remoteConnections'])
            ->toHaveCount(2)
            ->and($updated['remoteConnections'][1]['sshAlias'])
            ->toBe('app-node')
            ->and($updated['remoteConnections'][1]['projects'])
            ->toBe([
                [
                    'remotePath' => '/home/orbit/apps/docs-api',
                    'label' => 'Docs',
                ],
            ]);
    });

    it('removes only the matching project entry', function (): void {
        $config = [
            'version' => 1,
            'remoteConnections' => [
                [
                    'sshAlias' => 'app-node',
                    'projects' => [
                        ['remotePath' => '/home/orbit/apps/docs', 'label' => 'Docs'],
                        ['remotePath' => '/home/orbit/apps/billing', 'label' => 'Billing'],
                    ],
                ],
                [
                    'sshAlias' => 'other-node',
                    'projects' => [
                        ['remotePath' => '/srv/docs', 'label' => 'Docs'],
                    ],
                ],
            ],
        ];

        $result = app(CodexAppConfigMerger::class)->removeProject($config, 'Docs', 'app-node');

        expect($result['remoteConnections'][0]['projects'])
            ->toBe([
                ['remotePath' => '/home/orbit/apps/billing', 'label' => 'Billing'],
            ])
            ->and($result['remoteConnections'][1]['projects'])
            ->toBe([
                ['remotePath' => '/srv/docs', 'label' => 'Docs'],
            ]);
    });
});
