<?php

declare(strict_types=1);

use App\E2E\Support\SourceMountedCheckoutLifecycleLock;

/**
 * The lock holder is a real process. When it dies before printing the ready
 * marker (unreachable host, missing flock, unwritable path), acquisition must
 * fail loudly and promptly instead of polling the dead holder forever — the
 * failure mode that froze the in-memory suite.
 */
it('fails promptly when the lock holder dies without becoming ready', function (): void {
    $start = microtime(true);

    expect(fn (): mixed => new SourceMountedCheckoutLifecycleLock('local', '/dev/null/orbit-lock-target')
        ->run(static fn (): bool => true))
        ->toThrow(RuntimeException::class, 'Could not acquire source checkout lifecycle lock');

    expect(microtime(true) - $start)->toBeLessThan(10.0);
});
