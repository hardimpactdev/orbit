<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class SourceMountedCheckoutRsyncGuard
{
    public function __construct(
        private SourceMountedCheckoutMutationFence $mutationFence,
    ) {}

    public function remotePath(): string
    {
        return implode(' ', [
            self::guardPath(),
            $this->mutationFence->lockPath(),
            $this->mutationFence->generationFilePath(),
            $this->mutationFence->generation(),
        ]);
    }

    public function installationScript(): string
    {
        $guard = self::guardScript();
        $guardHash = hash('sha256', $guard);
        $delimiter = 'ORBIT_RSYNC_GUARD_'.strtoupper($guardHash);

        return implode("\n", [
            'guard_directory='.escapeshellarg(self::guardDirectory()),
            'guard_path='.escapeshellarg(self::guardPath()),
            'expected_guard_hash='.escapeshellarg($guardHash),
            'mkdir -p "$guard_directory"',
            'chmod 700 "$guard_directory"',
            'guard_temp="$(mktemp "$guard_directory/.rsync-guard.XXXXXX")"',
            'trap \'rm -f -- "$guard_temp"\' EXIT',
            'cat >"$guard_temp" <<\''.$delimiter.'\'',
            rtrim(string: $guard, characters: "\n"),
            $delimiter,
            'chmod 500 "$guard_temp"',
            'actual_guard_hash="$(sha256sum "$guard_temp" | cut -d " " -f 1)"',
            'if [ "$actual_guard_hash" != "$expected_guard_hash" ]; then echo "Generated rsync guard hash did not match its content address." >&2; exit 1; fi',
            'if [ ! -e "$guard_path" ]; then ln "$guard_temp" "$guard_path" 2>/dev/null || true; fi',
            'actual_guard_hash="$(sha256sum "$guard_path" | cut -d " " -f 1)"',
            'if [ "$actual_guard_hash" != "$expected_guard_hash" ]; then echo "Installed rsync guard does not match its content address." >&2; exit 1; fi',
            'if [ ! -f "$guard_path" ] || [ ! -x "$guard_path" ]; then echo "Installed rsync guard is not an executable regular file." >&2; exit 1; fi',
            'rm -f -- "$guard_temp"',
            'trap - EXIT',
        ]);
    }

    private static function guardDirectory(): string
    {
        return SourceMountedCheckoutMutationFence::LOCK_DIRECTORY.'/helpers';
    }

    private static function guardPath(): string
    {
        return self::guardDirectory().'/rsync-guard-'.hash('sha256', self::guardScript());
    }

    private static function guardScript(): string
    {
        return implode("\n", [
            '#!/usr/bin/env bash',
            'set -euo pipefail',
            'if [ "$#" -lt 4 ]; then echo "The rsync mutation guard requires lock, generation, and rsync arguments." >&2; exit 2; fi',
            'mutation_lock="$1"',
            'generation_file="$2"',
            'expected_generation="$3"',
            'shift 3',
            'exec 8>"$mutation_lock"',
            'if ! flock -w '
                .SourceMountedCheckoutMutationFence::WAIT_SECONDS
                .' -x 8; then echo "Timed out waiting for source mutation lock $mutation_lock" >&2; exit 1; fi',
            'actual_generation="$(cat "$generation_file" 2>/dev/null || printf "")"',
            'if [ "$actual_generation" != "$expected_generation" ]; then echo "Source lifecycle generation changed before mutation; refusing stale mutation." >&2; exit 1; fi',
            'exec flock -n -x 8 rsync "$@"',
            '',
        ]);
    }
}
