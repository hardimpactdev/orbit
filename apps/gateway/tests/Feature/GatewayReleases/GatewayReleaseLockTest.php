<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use Illuminate\Filesystem\Filesystem;

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
        $step = fopen($lock->stepPath(), 'r');
        flock($step, LOCK_EX);
        expect(fn () => $lock->run(fn () => null))->toThrow(GatewayReleaseException::class, 'still runs');
        fclose($step);
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

it('counts a released step lock as running while its recorded command service has processes', function (string $events, ?string $expected): void {
    $directory = sys_get_temp_dir().'/release-lock-'.bin2hex(random_bytes(6));
    $cgroups = $directory.'/cgroup';
    $group = '/system.slice/orbit-command-0123.service';
    mkdir($cgroups.$group, 0700, true);
    if ($events !== 'removed') {
        file_put_contents($cgroups.$group.'/cgroup.events', $events);
    }
    $lock = new GatewayReleaseLock($directory.'/gateway-release.lock', $cgroups);
    $lock->run(static fn () => null);
    file_put_contents($lock->stepPath(), $group."\n");
    try {
        $failure = $expected === null ? null : release_failure(fn () => $lock->run(static fn () => throw new LogicException('The operation must not start.')));
        if ($expected === null) {
            expect($lock->run(static fn (): string => 'ran'))->toBe('ran');
        } else {
            expect($failure?->errorCode)->toBe($expected);
        }
    } finally {
        new Filesystem()->deleteDirectory($directory);
    }
})->with([
    'populated' => ["populated 1\nfrozen 0\n", 'gateway.release_in_progress'],
    'empty' => ["populated 0\nfrozen 0\n", null],
    'removed' => ['removed', null],
    'unreadable state' => ["frozen 0\n", 'gateway.release_lock_unavailable'],
]);

it('refuses a step lock record that names no command service', function (): void {
    $directory = sys_get_temp_dir().'/release-lock-'.bin2hex(random_bytes(6));
    $lock = new GatewayReleaseLock($directory.'/gateway-release.lock', $directory.'/cgroup');
    $lock->run(static fn () => null);
    file_put_contents($lock->stepPath(), "/system.slice/../etc\n");
    try {
        expect(release_failure(fn () => $lock->run(static fn () => throw new LogicException('The operation must not start.')))->errorCode)->toBe('gateway.release_lock_unavailable');
    } finally {
        new Filesystem()->deleteDirectory($directory);
    }
});

it('does not hand the operation lock to child processes', function (): void {
    $directory = sys_get_temp_dir().'/release-lock-'.bin2hex(random_bytes(6));
    $lock = new GatewayReleaseLock($directory.'/gateway-release.lock');
    try {
        $descriptors = $lock->run(fn (): string => new NativeProcessRunner()->run(
            new ProcessInvocation(['sh', '-c', 'ls -l /proc/$$/fd'], timeout: 10.0),
        )->stdout);
        expect($descriptors)->not->toContain('gateway-release.lock');
    } finally {
        unlink($directory.'/gateway-release.lock');
        unlink($directory.'/gateway-release-step.lock');
        rmdir($directory);
    }
});
