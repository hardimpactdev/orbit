<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Support\NodeUpdateLockFixture;

pest()->group('subprocess');

it('gives up after its wait while another process holds the lock', function (): void {
    $dir = NodeUpdateLockFixture::directory();
    touch($dir.'/update.lock');
    $holder = new Process(['setsid', 'flock', $dir.'/update.lock', 'sleep', '20']);

    try {
        $holder->start();
        NodeUpdateLockFixture::waitUntilFree($dir, free: false);
        expect(NodeUpdateLockFixture::free($dir))->toBeFalse();

        [$status, $seconds] = NodeUpdateLockFixture::acquire($dir, 'orbit-update-lock-busy', 1);

        expect($status)->toBe(3)
            ->and(is_file($dir.'/orbit-update-lock-busy.held'))->toBeFalse()
            ->and($seconds)->toBeLessThan(10)
            ->and(NodeUpdateLockFixture::free($dir))->toBeFalse();
    } finally {
        if ($holder->isRunning()) {
            $pid = $holder->getPid();
            if ($pid !== null) {
                posix_kill(-$pid, SIGTERM);
            }
            $holder->stop();
        }
        new Filesystem()->deleteDirectory($dir);
    }
});
