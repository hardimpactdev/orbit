<?php

declare(strict_types=1);

use Orbit\Sdk\Responses\Nodes\AppsSettings;
use Orbit\Sdk\Responses\Nodes\CiSettings;
use Orbit\Sdk\Responses\Nodes\NodeSettings;

describe(NodeSettings::class, function (): void {
    it('maps only the raw apps root setting', function (): void {
        $settings = NodeSettings::fromGatewayData([
            'apps' => ['path' => '/srv/orbit/apps'],
            'instance' => ['path' => '/legacy/instances'],
            'worktree' => ['path' => '/legacy/worktrees'],
        ]);

        expect($settings->apps)
            ->toBeInstanceOf(AppsSettings::class)
            ->and($settings->apps?->path)
            ->toBe('/srv/orbit/apps')
            ->and($settings->toArray())
            ->toBe(['apps' => ['path' => '/srv/orbit/apps']])
            ->and($settings->isEmpty())
            ->toBeFalse();
    });

    it('reads a Node body without task_vms and keeps none from a body that still names it', function (): void {
        expect(NodeSettings::fromGatewayData(['apps' => ['path' => '/srv/orbit/apps']])->toArray())
            ->toBe(['apps' => ['path' => '/srv/orbit/apps']])
            ->and(NodeSettings::fromGatewayData(['task_vms' => ['cidr' => '10.251.77.0/24', 'task_places' => 4, 'ci_places' => 2]])->isEmpty())
            ->toBeTrue();
    });

    it('maps a CI Node\'s CI places and leaves them out when absent or malformed', function (): void {
        $settings = NodeSettings::fromGatewayData(['ci' => ['places' => 4, 'proof_only' => true]]);
        expect($settings->ci)->toEqual(new CiSettings(4, true))
            ->and($settings->isEmpty())->toBeFalse()
            ->and($settings->toArray())->toBe(['apps' => null, 'ci' => ['places' => 4, 'proof_only' => true]])
            ->and(NodeSettings::fromGatewayData(['ci' => ['places' => 0]])->ci)->toEqual(new CiSettings(0, false));
        foreach ([[], ['ci' => null], ['ci' => ['proof_only' => true]], ['ci' => ['places' => '4']]] as $data) {
            expect(NodeSettings::fromGatewayData($data)->ci)->toBeNull()
                ->and(NodeSettings::fromGatewayData($data)->toArray())->not->toHaveKey('ci');
        }
    });

    it('bounds malformed apps settings without restoring legacy keys', function (): void {
        $settings = NodeSettings::fromGatewayData([
            'apps' => 'invalid',
            'instance' => ['path' => '/legacy/instances'],
        ]);

        expect($settings->apps)
            ->toBeNull()
            ->and($settings->toArray())
            ->toBe(['apps' => null])
            ->and($settings->isEmpty())
            ->toBeTrue();
    });
});
