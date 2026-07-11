<?php

declare(strict_types=1);

namespace App\E2E\Support;

use LogicException;

final readonly class SourceMountedCheckoutMutationFence
{
    public const string LOCK_DIRECTORY = '/tmp/orbit-e2e-source-locks';

    public const int WAIT_SECONDS = 1200;

    public function __construct(
        private string $sourcePath,
        private string $generation,
    ) {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $this->generation) !== 1) {
            throw new LogicException('The source lifecycle generation must be a 32-character lowercase hex token.');
        }
    }

    public function guardedScript(string $script): string
    {
        return implode("\n", [
            'mutation_lock='.escapeshellarg($this->lockPath()),
            'generation_file='.escapeshellarg($this->generationFilePath()),
            'expected_generation='.escapeshellarg($this->generation),
            'exec 8>"$mutation_lock"',
            'if ! flock -w '
                .self::WAIT_SECONDS
                .' -x 8; then echo "Timed out waiting for source mutation lock $mutation_lock" >&2; exit 1; fi',
            'actual_generation="$(cat "$generation_file" 2>/dev/null || printf "")"',
            'if [ "$actual_generation" != "$expected_generation" ]; then echo "Source lifecycle generation changed before mutation; refusing stale mutation." >&2; exit 1; fi',
            $script,
        ]);
    }

    public function rsyncGuard(): SourceMountedCheckoutRsyncGuard
    {
        return new SourceMountedCheckoutRsyncGuard($this);
    }

    public function releaseGuardScript(): string
    {
        return "flock -u 8\nexec 8>&-";
    }

    public function dockerLockMount(): string
    {
        return 'type=bind,src='.self::LOCK_DIRECTORY.',dst='.self::LOCK_DIRECTORY;
    }

    public function dockerGuardedScript(string $script): string
    {
        $guardedPayload = implode("\n", [
            'set -eu',
            'actual_generation="$(cat "$generation_file" 2>/dev/null || printf "")"',
            'if [ "$actual_generation" != "$expected_generation" ]; then echo "Source lifecycle generation changed before mutation; refusing stale mutation." >&2; exit 1; fi',
            $script,
        ]);

        return implode(' ', [
            'mutation_lock='.escapeshellarg($this->lockPath()).';',
            'generation_file='.escapeshellarg($this->generationFilePath()).';',
            'expected_generation='.escapeshellarg($this->generation).';',
            'export generation_file expected_generation;',
            'timeout '.self::WAIT_SECONDS,
            'flock "$mutation_lock"',
            'sh -lc '.escapeshellarg($guardedPayload),
        ]);
    }

    public function lockPath(): string
    {
        return self::LOCK_DIRECTORY.'/'.self::sourceHash($this->sourcePath).'.mutation.lock';
    }

    public function generationFilePath(): string
    {
        return self::LOCK_DIRECTORY.'/'.self::sourceHash($this->sourcePath).'.generation';
    }

    public function generation(): string
    {
        return $this->generation;
    }

    public static function protectedSourceCleanupScript(string $sourcePath): string
    {
        return implode("\n", [
            'target='.escapeshellarg($sourcePath),
            'if [ -d "$target" ]; then',
            '  find "$target" -mindepth 1 -maxdepth 1 ! -name \'.orbit-e2e-source-sync.lock\' -exec rm -rf -- {} +',
            'fi',
        ]);
    }

    private static function sourceHash(string $sourcePath): string
    {
        $sourcePath = rtrim(string: $sourcePath, characters: '/');

        return hash('sha256', $sourcePath !== '' ? $sourcePath : '/');
    }
}
