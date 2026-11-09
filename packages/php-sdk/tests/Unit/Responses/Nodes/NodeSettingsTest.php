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
            'cidr' => '10.251.77.0/24', 'task_places' => 4, 'ci_places' => 2,
            'image_alias' => 'custom-base', 'operator_owned' => [], 'pool_size' => '640GiB',
        ]]);
        expect($settings->isEmpty())->toBeFalse()
            ->and($settings->task_vms?->task_places)->toBe(4)
            ->and($settings->toArray()['task_vms'])->toEqual([
                'cidr' => '10.251.77.0/24', 'task_places' => 4, 'ci_places' => 2,
                'image_alias' => 'custom-base', 'operator_owned' => [], 'pool_size' => '640GiB',
            ]);
    });

    it('maps the task container side and leaves it out when absent or malformed', function (): void {
        $containers = ['cidr' => '10.251.78.0/24', 'task_places' => 1, 'ci_places' => 0, 'project' => 'orbit-task-containers', 'network' => 'orbittaskc0'];
        $settings = NodeSettings::fromGatewayData(['task_vms' => ['cidr' => '10.251.77.0/24', 'task_places' => 2, 'ci_places' => 2, 'containers' => $containers]]);
        expect($settings->task_vms?->containers)->toBe($containers)
            ->and($settings->toArray()['task_vms']['containers'] ?? null)->toBe($containers)
            ->and(NodeSettings::fromGatewayData(['task_vms' => ['cidr' => '10.251.77.0/24', 'task_places' => 2, 'ci_places' => 2, 'containers' => ['cidr' => '10.251.78.0/24']]])->toArray()['task_vms'])->not->toHaveKey('containers');
    });

    it('preserves separate pool sizes, including zero', function (): void {
        $settings = NodeSettings::fromGatewayData(['task_vms' => ['cidr' => '10.251.77.0/24', 'task_places' => 0, 'ci_places' => 2]]);
        expect($settings->task_vms?->task_places)->toBe(0)->and($settings->task_vms?->ci_places)->toBe(2);
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
