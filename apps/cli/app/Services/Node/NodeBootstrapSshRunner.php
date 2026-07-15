<?php

declare(strict_types=1);

namespace App\Services\Node;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final class NodeBootstrapSshRunner
{
    private const int CONNECT_TIMEOUT_SECONDS = 10;

    private const int BOOTSTRAP_TIMEOUT_SECONDS = 900;

    public function run(
        string $host,
        string $user,
        string $script,
        ?string $expectedFingerprint = null,
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

            return Process::timeout(self::BOOTSTRAP_TIMEOUT_SECONDS)
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

        $fingerprint = Process::timeout(self::CONNECT_TIMEOUT_SECONDS)
            ->input($scan->output())
            ->run(['ssh-keygen', '-E', 'sha256', '-lf', '-']);

        if (! $fingerprint->successful()) {
            throw new RuntimeException("Could not fingerprint the SSH host key for {$host}.");
        }

        if (preg_match_all('/\b(SHA256:[A-Za-z0-9+\/=]+)\b/', $fingerprint->output(), $matches) < 1) {
            throw new RuntimeException("SSH host key fingerprint output for {$host} is invalid.");
        }

        $matchesExpectedFingerprint = array_any(
            $matches[1],
            static fn (string $observedFingerprint): bool => hash_equals(
                $expectedFingerprint,
                $observedFingerprint,
            ),
        );

        if (! $matchesExpectedFingerprint) {
            throw new NodeBootstrapHostKeyMismatch("SSH host key fingerprint mismatch for {$host}.");
        }

        $path = tempnam(sys_get_temp_dir(), 'orbit-bootstrap-known-hosts-');

        if (! is_string($path)) {
            throw new RuntimeException('Could not create a temporary SSH known-hosts file.');
        }

        if (file_put_contents($path, $scan->output()) === false || ! chmod($path, 0o600)) {
            File::delete($path);

            throw new RuntimeException('Could not secure the temporary SSH known-hosts file.');
        }

        return $path;
    }
}
