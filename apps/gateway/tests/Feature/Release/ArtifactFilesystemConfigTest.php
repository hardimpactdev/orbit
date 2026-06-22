<?php

declare(strict_types=1);

it('configures a dedicated release artifact filesystem disk', function (): void {
    expect(config('orbit.artifacts.disk'))->toBe('orbit-artifacts')
        ->and(config('filesystems.disks.orbit-artifacts'))->toMatchArray([
            'driver' => 's3',
            'region' => 'us-east-1',
            'use_path_style_endpoint' => true,
            'visibility' => 'public',
            'throw' => true,
        ]);
});
