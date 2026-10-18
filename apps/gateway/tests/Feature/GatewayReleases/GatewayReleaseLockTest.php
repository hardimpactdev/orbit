<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;

it('creates both locks privately under a permissive umask and preserves their inodes and exclusion', function (): void {
    $directory = sys_get_temp_dir().'/release-lock-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $path = $directory.'/gateway-release.lock';
    $lock = new GatewayReleaseLock($path);
    $mask = umask(0002);
    try {
        $lock->run(function () use ($path, $lock): void {
            expect(umask())->toBe(0002);
            foreach ([$path, $lock->stepPath()] as $file) {
                expect(fileperms($file) & 07777)->toBe(0600);
            }
            expect(fn () => $lock->run(fn () => null))->toThrow(GatewayReleaseException::class);
        });
        $inodes = [fileinode($path), fileinode($lock->stepPath())];
        $held = fopen($lock->stepPath(), 'r+');
        flock($held, LOCK_EX);
        try {
            expect(fn () => $lock->run(fn () => null))->toThrow(GatewayReleaseException::class);
        } finally {
            fclose($held);
        }
        expect($lock->run(fn () => 'ran'))->toBe('ran');
        expect([fileinode($path), fileinode($lock->stepPath())])->toBe($inodes);
    } finally {
        umask($mask);
        @unlink($path);
        @unlink($lock->stepPath());
        rmdir($directory);
    }
});

it('refuses an unsafe existing lock without repairing or replacing either inode', function (string $name, string $fault): void {
    $directory = sys_get_temp_dir().'/release-lock-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $path = $directory.'/'.$name;
    $target = $directory.'/target';
    file_put_contents($target, 'keep');
    chmod($target, 0600);
    if ($fault === 'symlink') {
        symlink($target, $path);
    } else {
        file_put_contents($path, 'keep');
        chmod($path, 0664);
    }
    $before = lstat($path);
    $mask = umask();
    try {
        expect(fn () => new GatewayReleaseLock($directory.'/gateway-release.lock')->run(fn () => null))->toThrow(GatewayReleaseException::class);
        clearstatcache(true, $path);
        expect(lstat($path))->toBe($before);
        expect(file_get_contents($target))->toBe('keep');
        expect(umask())->toBe($mask);
        expect(glob($directory.'/*'))->toHaveCount(2);
    } finally {
        unlink($path);
        unlink($target);
        rmdir($directory);
    }
})->with(['gateway-release.lock', 'gateway-release-step.lock'])->with(['symlink', 'permissive']);

it('refuses a foreign existing regular file without mutation', function (): void {
    $path = '/etc/passwd';
    expect(fileowner($path))->not->toBe(posix_geteuid());
    $before = lstat($path);
    $hash = hash_file('sha256', $path);
    expect(fn () => new GatewayReleaseLock($path)->run(fn () => null))->toThrow(GatewayReleaseException::class);
    clearstatcache(true, $path);
    expect(lstat($path))->toBe($before);
    expect(hash_file('sha256', $path))->toBe($hash);
});
