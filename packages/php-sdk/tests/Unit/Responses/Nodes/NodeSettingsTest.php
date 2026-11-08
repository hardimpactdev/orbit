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
            'image_alias' => 'custom-base', 'operator_owned' => [], 'ci_only' => true, 'pool_size' => '640GiB',
        ]]);
        expect($settings->isEmpty())->toBeFalse()
            ->and($settings->task_vms?->max_vms)->toBe(4)
            ->and($settings->toArray()['task_vms'])->toBe([
                'cidr' => '10.251.77.0/24', 'max_vms' => 4,
                'image_alias' => 'custom-base', 'operator_owned' => [], 'ci_only' => true, 'pool_size' => '640GiB',
            ]);
    });

    it('preserves explicit CI-only settings and ignores non-booleans', function (mixed $value, ?bool $expected): void {
        $settings = NodeSettings::fromGatewayData(['task_vms' => ['cidr' => '10.251.77.0/24', 'max_vms' => 6, 'ci_only' => $value]]);
        expect($settings->task_vms?->ci_only)->toBe($expected);
        expect(array_key_exists('ci_only', $settings->task_vms->toArray()))->toBe($expected !== null);
    })->with([[true, true], [false, false], ['true', null], [1, null], [null, null]]);

    it('preserves VM count limits including zero and ignores non-integers', function (mixed $value, ?int $expected): void {
        $settings = NodeSettings::fromGatewayData(['task_vms' => ['cidr' => '10.251.77.0/24', 'max_vms' => 12, 'task_max_vms' => $value]]);
        expect($settings->task_vms?->task_max_vms)->toBe($expected);
        expect(array_key_exists('task_max_vms', $settings->task_vms->toArray()))->toBe($expected !== null);
    })->with([[0, 0], [2, 2], ['2', null], [true, null], [null, null]]);

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
