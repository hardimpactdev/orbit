<?php

declare(strict_types=1);

namespace App\Services\SelfUpdate;

use App\Support\EffectiveUser;
use App\Support\OrbitHome;
use Phar;

final readonly class NativeSelfUpdateHost implements SelfUpdateHost
{
    public function __construct(
        /** `PHP_BINARY`, which the static PHP inside a standalone binary leaves empty. */
        private string $phpBinary = PHP_BINARY,
    ) {}

    public function runningBinary(): RunningBinary
    {
        $phar = Phar::running(false);

        if ($phar === '') {
            return new RunningBinary(RunningBinaryKind::Source, null);
        }

        $path = realpath($phar);

        if ($path === false || ! is_file($path)) {
            return new RunningBinary(RunningBinaryKind::Phar, $phar);
        }

        // A standalone binary is a static PHP that runs the PHAR appended to itself, so the process executable is
        // the PHAR file. A PHAR started by a separate `php` has that `php` as its executable. On Linux the kernel
        // names the executable; elsewhere the static PHP reports an empty PHP_BINARY.
        $executable = match (true) {
            PHP_OS_FAMILY === 'Linux' => @realpath('/proc/self/exe'),
            $this->phpBinary === '' => $path,
            default => @realpath($this->phpBinary),
        };

        return new RunningBinary($executable === $path ? RunningBinaryKind::Binary : RunningBinaryKind::Phar, $path);
    }

    public function platform(): ?string
    {
        return self::releasePlatform(PHP_OS_FAMILY, php_uname('m'));
    }

    /** The release platform of an OS family and machine name. Linux releases are x86_64 only. */
    public static function releasePlatform(string $osFamily, string $machine): ?string
    {
        $machine = strtolower($machine);

        return match (true) {
            $osFamily === 'Linux' && in_array($machine, ['x86_64', 'amd64'], true) => 'linux-x86_64',
            $osFamily === 'Darwin' && $machine === 'arm64' => 'macos-arm64',
            default => null,
        };
    }

    public function isRoot(): bool
    {
        return EffectiveUser::id() === 0;
    }

    public function exitAfterReplacingItself(int $status): never
    {
        exit($status);
    }

    public function lockPath(): string
    {
        return match (true) {
            ! $this->isRoot() => OrbitHome::path().'/self-update.lock',
            PHP_OS_FAMILY === 'Linux' => '/run/lock/orbit-self-update.lock',
            default => '/var/run/orbit-self-update.lock',
        };
    }
}
