<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tests\Support\NodeUpdateLockFixture;

describe('Node update lock script', function (): void {
    it('holds the lock until its unit stops', function (): void {
        $dir = NodeUpdateLockFixture::directory();

        try {
            [$status] = NodeUpdateLockFixture::acquire($dir, 'orbit-update-lock-test', 5);

            expect($status)->toBe(0)
                ->and(is_file($dir.'/orbit-update-lock-test.held'))->toBeTrue()
                ->and(NodeUpdateLockFixture::free($dir))->toBeFalse();

            NodeUpdateLockFixture::stop($dir, 'orbit-update-lock-test');

            expect(NodeUpdateLockFixture::free($dir))->toBeTrue();
        } finally {
            NodeUpdateLockFixture::stop($dir, 'orbit-update-lock-test');
            new Filesystem()->deleteDirectory($dir);
        }
    });
});
