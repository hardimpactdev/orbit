<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\GatewayReleases\GatewayReleaseCommands;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

beforeEach(function (): void {
    $node = Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.2', 'status' => LifecycleStatus::Active]);
    $node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->agents = new FakeAgentCommandRunner()->answer('gateway.inspect-release-step', ['busy' => false]);
    $this->commands = new GatewayReleaseCommands($this->agents);
});

it('creates both locks privately under a permissive umask and preserves their inodes and exclusion', function (): void {
    $directory = sys_get_temp_dir().'/release-lock-'.bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $path = $directory.'/gateway-release.lock';
    $lock = new GatewayReleaseLock($path, $this->commands);
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
        $this->agents->answer('gateway.inspect-release-step', ['busy' => true]);
        expect(fn () => $lock->run(fn () => null))->toThrow(GatewayReleaseException::class, 'still runs');
        $this->agents->answer('gateway.inspect-release-step', ['busy' => false]);
        expect($lock->run(fn () => 'ran'))->toBe('ran');
        expect([fileinode($path), fileinode($lock->stepPath())])->toBe($inodes);
        expect($this->agents->calls)->toBe(array_fill(0, 3, [
            'node' => 'gateway', 'command' => 'gateway.inspect-release-step', 'input' => ['step_lock' => $lock->stepPath()],
        ]));
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
        expect(fn () => new GatewayReleaseLock($directory.'/gateway-release.lock', $this->commands)->run(fn () => null))->toThrow(GatewayReleaseException::class);
        clearstatcache(true, $path);
        expect(lstat($path))->toBe($before);
        expect(file_get_contents($target))->toBe('keep');
        expect(umask())->toBe($mask);
        expect(glob($directory.'/*'))->toHaveCount(2);
        expect($this->agents->calls)->toBe([]);
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
    expect(fn () => new GatewayReleaseLock($path, $this->commands)->run(fn () => null))->toThrow(GatewayReleaseException::class);
    clearstatcache(true, $path);
    expect(lstat($path))->toBe($before);
    expect(hash_file('sha256', $path))->toBe($hash);
});

it('refuses a missing native step result before starting the operation', function (): void {
    $directory = sys_get_temp_dir().'/release-lock-'.bin2hex(random_bytes(6));
    $lock = new GatewayReleaseLock($directory.'/gateway-release.lock', $this->commands);
    $this->agents->answerMalformed('gateway.inspect-release-step', []);
    try {
        $failure = release_failure(fn () => $lock->run(static fn () => throw new LogicException('The operation must not start.')));
        expect($failure->errorCode)->toBe('gateway.release_lock_unavailable');
    } finally {
        unlink($directory.'/gateway-release.lock');
        unlink($directory.'/gateway-release-step.lock');
        rmdir($directory);
    }
});

it('does not hand the operation lock to child processes', function (): void {
    $directory = sys_get_temp_dir().'/release-lock-'.bin2hex(random_bytes(6));
    $lock = new GatewayReleaseLock($directory.'/gateway-release.lock', $this->commands);
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
