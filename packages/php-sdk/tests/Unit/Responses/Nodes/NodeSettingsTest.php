<?php

declare(strict_types=1);

use Orbit\Sdk\Responses\Nodes\AppsSettings;
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
            ->toBe(['apps' => ['path' => '/srv/orbit/apps'], 'task_vms' => null])
            ->and($settings->isEmpty())
            ->toBeFalse();
    });

    it('maps typed task VM settings without inventing role intent', function (): void {
        $settings = NodeSettings::fromGatewayData(['task_vms' => [
            'cidr' => '10.251.77.0/24', 'max_vms' => 4,
            'image_alias' => 'custom-base', 'operator_owned' => [],
        ]]);
        expect($settings->isEmpty())->toBeFalse()
            ->and($settings->task_vms?->max_vms)->toBe(4)
            ->and($settings->toArray()['task_vms'])->toBe([
                'cidr' => '10.251.77.0/24', 'max_vms' => 4,
                'image_alias' => 'custom-base', 'operator_owned' => [],
            ]);
    });

    it('bounds malformed apps settings without restoring legacy keys', function (): void {
        $settings = NodeSettings::fromGatewayData([
            'apps' => 'invalid',
            'instance' => ['path' => '/legacy/instances'],
        ]);

        expect($settings->apps)
            ->toBeNull()
            ->and($settings->toArray())
            ->toBe(['apps' => null, 'task_vms' => null])
            ->and($settings->isEmpty())
            ->toBeTrue();
    });
});
