<?php

declare(strict_types=1);

use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use Illuminate\Support\Facades\Artisan;

function readerCheckLockPath(): string
{
    $directory = sys_get_temp_dir().'/orbit-reader-check-'.bin2hex(random_bytes(4));
    mkdir($directory, 0700, true);

    return $directory.'/release.lock';
}

it('ends without error when a release holds the release lock', function (): void {
    $path = readerCheckLockPath();
    app()->instance(GatewayReleaseLock::class, new GatewayReleaseLock($path));
    $code = null;

    (new GatewayReleaseLock($path))->run(function () use (&$code): void {
        $code = Artisan::call('gateway:swamp-reader:check');
    });

    expect($code)->toBe(0)->and(Artisan::output())->toContain('release lock');
});

it('names the error code when the lock cannot be used, and fails', function (): void {
    app()->instance(GatewayReleaseLock::class, new GatewayReleaseLock('/dev/null/orbit/release.lock'));

    $code = Artisan::call('gateway:swamp-reader:check');

    expect($code)->toBe(1)->and(Artisan::output())->toContain('gateway.release_lock_unavailable');
});
