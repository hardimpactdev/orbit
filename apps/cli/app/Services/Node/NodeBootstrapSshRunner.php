<?php

declare(strict_types=1);

namespace App\Services\Node;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/** @mago-expect lint:cyclomatic-complexity */
final class NodeBootstrapSshRunner
{
    private const int CONNECT_TIMEOUT_SECONDS = 10;

    private const int BOOTSTRAP_TIMEOUT_SECONDS = 900;

    /**
     * @return array{platform: string, architecture: string}
     */
    public function inspectTarget(
        string $host,
        string $user,
        ?string $expectedFingerprint = null,
    ): array {
        $result = $this->runScript(
            host: $host,
            user: $user,
            script: <<<'BASH'
                # ORBIT_TARGET_PLATFORM
                set -euo pipefail
                if [ ! -r /etc/os-release ]; then
                    printf '%s\n' 'Target does not expose /etc/os-release.' >&2
                    exit 1
                fi
                . /etc/os-release
                PLATFORM="$(printf '%s_%s' "$ID" "$VERSION_ID" | tr '[:upper:].' '[:lower:]-')"
                ARCHITECTURE="$(uname -m)"
                case "$ARCHITECTURE" in
                    x86_64|amd64)
                        ARCHITECTURE=amd64
                        ;;
                    aarch64|arm64)
                        ARCHITECTURE=arm64
                        ;;
                esac
                printf '%s\n%s\n' "$PLATFORM" "$ARCHITECTURE"
                BASH,
            timeout: self::CONNECT_TIMEOUT_SECONDS,
            expectedFingerprint: $expectedFingerprint,
        );

        if (! $result->successful()) {
            throw new RuntimeException(
                trim($result->errorOutput()) ?: "Could not inspect target platform on {$user}@{$host}.",
            );
        }

        $lines = preg_split('/\R/', trim($result->output()));
        $platform = is_array($lines) && is_string($lines[0] ?? null) ? trim($lines[0]) : '';
        $architecture = is_array($lines) && is_string($lines[1] ?? null) ? trim($lines[1]) : '';

        if (
            ! in_array($platform, ['ubuntu_24-04', 'ubuntu_26-04'], true)
            || ! in_array($architecture, ['amd64', 'arm64'], true)
        ) {
            throw new NodeBootstrapUnsupportedPlatform(
                platform: $platform !== '' ? $platform : 'unknown',
                architecture: $architecture !== '' ? $architecture : 'unknown',
            );
        }

        return [
            'platform' => $platform,
            'architecture' => $architecture,
        ];
    }

    public function run(
        string $host,
        string $user,
        string $script,
        ?string $expectedFingerprint = null,
    ): ProcessResult {
        return $this->runScript(
            host: $host,
            user: $user,
            script: $script,
            timeout: self::BOOTSTRAP_TIMEOUT_SECONDS,
            expectedFingerprint: $expectedFingerprint,
        );
    }

    private function runScript(
        string $host,
        string $user,
        string $script,
        int $timeout,
        ?string $expectedFingerprint,
    ): ProcessResult {
        $knownHostsPath = null;

        try {
            $hostKeyOptions = ['-o', 'StrictHostKeyChecking=accept-new'];

            if (is_string($expectedFingerprint) && trim($expectedFingerprint) !== '') {
                $knownHostsPath = $this->verifiedKnownHostsFile($host, trim($expectedFingerprint));
                $hostKeyOptions = [
                    '-o',
                    'StrictHostKeyChecking=yes',
                    '-o',
                    "UserKnownHostsFile={$knownHostsPath}",
                ];
            }

            return Process::timeout($timeout)
                ->input($script)
                ->run([
                    'ssh',
                    '-o',
                    'BatchMode=yes',
                    '-o',
                    'IdentitiesOnly=no',
                    '-o',
                    'ConnectTimeout='.self::CONNECT_TIMEOUT_SECONDS,
                    ...$hostKeyOptions,
                    "{$user}@{$host}",
                    'bash -s --',
                ]);
        } finally {
            if ($knownHostsPath !== null) {
                File::delete($knownHostsPath);
            }
        }
    }

    private function verifiedKnownHostsFile(string $host, string $expectedFingerprint): string
    {
        $scan = Process::timeout(self::CONNECT_TIMEOUT_SECONDS)->run([
            'ssh-keyscan',
            '-T',
            (string) self::CONNECT_TIMEOUT_SECONDS,
            '--',
            $host,
        ]);

        if (! $scan->successful() || trim($scan->output()) === '') {
            throw new RuntimeException("Could not read the SSH host key for {$host}.");
        }

        $approvedRows = $this->approvedKnownHostRows($host, $scan->output(), $expectedFingerprint);

        if ($approvedRows === []) {
            throw new NodeBootstrapHostKeyMismatch("SSH host key fingerprint mismatch for {$host}.");
        }

        $path = tempnam(sys_get_temp_dir(), 'orbit-bootstrap-known-hosts-');

        if (! is_string($path)) {
            throw new RuntimeException('Could not create a temporary SSH known-hosts file.');
        }

        if (file_put_contents($path, implode("\n", $approvedRows)."\n") === false || ! chmod($path, 0o600)) {
            File::delete($path);

            throw new RuntimeException('Could not secure the temporary SSH known-hosts file.');
        }

        return $path;
    }

    /**
     * @return list<string>
     */
    private function approvedKnownHostRows(string $host, string $scan, string $expectedFingerprint): array
    {
        $splitRows = preg_split('/\R/', trim($scan));
        $rows = array_values(array_filter(
            is_array($splitRows) ? $splitRows : [],
            static fn (string $row): bool => trim($row) !== '' && ! str_starts_with(ltrim($row), '#'),
        ));
        $approved = [];

        foreach ($rows as $row) {
            $fingerprint = Process::timeout(self::CONNECT_TIMEOUT_SECONDS)
                ->input($row."\n")
                ->run(['ssh-keygen', '-E', 'sha256', '-lf', '-']);

            if (! $fingerprint->successful()) {
                throw new RuntimeException("Could not fingerprint the SSH host key for {$host}.");
            }

            $matches = [];

            if (preg_match('/\b(SHA256:[A-Za-z0-9+\/=]+)\b/', $fingerprint->output(), $matches) !== 1) {
                throw new RuntimeException("SSH host key fingerprint output for {$host} is invalid.");
            }

            if (hash_equals($expectedFingerprint, $matches[1])) {
                $approved[] = $row;
            }
        }

        return $approved;
    }
}
