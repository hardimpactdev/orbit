<?php

declare(strict_types=1);

namespace App\Services\Certificates;

use Symfony\Component\Process\Process;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
final readonly class LocalSiteCertificateInstallAction
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function install(array $payload): array
    {
        $certPath = $this->path($payload['cert_path'] ?? null, '.crt');
        $keyPath = $this->path($payload['key_path'] ?? null, '.key');
        $cert = $this->contents($payload['cert'] ?? null, 'cert');
        $key = $this->contents($payload['key'] ?? null, 'key');
        $owner = $this->owner($payload['owner'] ?? null);

        if (dirname($certPath) !== dirname($keyPath)) {
            throw new LocalSiteCertificateInstallFailure(
                errorCode: 'validation_failed',
                message: 'Site certificate paths must share a directory.',
                meta: ['field' => 'key_path'],
            );
        }

        if ($this->shouldInstallAsCurrentUser($certPath, $keyPath, $owner)) {
            $this->installAsCurrentUser($certPath, $keyPath, $cert, $key);

            return $this->installResult($certPath, $keyPath, $owner, $cert, $key);
        }

        $this->installWithSudo($certPath, $keyPath, $cert, $key, $owner);

        return $this->installResult($certPath, $keyPath, $owner, $cert, $key);
    }

    /**
     * @return array<string, mixed>
     */
    private function installResult(
        string $certPath,
        string $keyPath,
        ?string $owner,
        string $cert,
        string $key,
    ): array {
        return [
            'cert_path' => $certPath,
            'key_path' => $keyPath,
            'owner' => $owner,
            'cert_bytes' => strlen($cert),
            'key_bytes' => strlen($key),
        ];
    }

    private function installAsCurrentUser(string $certPath, string $keyPath, string $cert, string $key): void
    {
        $directory = dirname($certPath);

        if (! is_dir($directory) && ! mkdir($directory, permissions: 0o755, recursive: true) && ! is_dir($directory)) {
            throw new LocalSiteCertificateInstallFailure(
                errorCode: 'site_certificate.directory_failed',
                message: 'Site certificate directory could not be created.',
                meta: ['path' => $directory],
            );
        }

        $this->writeLocalFile($certPath, $cert);
        $this->writeLocalFile($keyPath, $key);
        $this->chmodLocal($certPath, 0o644);
        $this->chmodLocal($keyPath, 0o600);
    }

    private function installWithSudo(
        string $certPath,
        string $keyPath,
        string $cert,
        string $key,
        ?string $owner,
    ): void {
        $this->mustRun(['sudo', '-n', 'install', '-d', '-m', '0755', dirname($certPath)]);
        $this->writeSudoFile($certPath, $cert);
        $this->writeSudoFile($keyPath, $key);
        $this->mustRun(['sudo', '-n', 'chmod', '0644', $certPath]);
        $this->mustRun(['sudo', '-n', 'chmod', '0600', $keyPath]);

        if ($owner !== null) {
            $this->chown($owner, $certPath);
            $this->chown($owner, $keyPath);
        }
    }

    private function writeLocalFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) !== false) {
            return;
        }

        throw new LocalSiteCertificateInstallFailure(
            errorCode: 'site_certificate.write_failed',
            message: 'Site certificate file could not be written.',
            meta: [
                'path' => $path,
                'exit_code' => null,
                'output' => 'file_put_contents failed',
            ],
        );
    }

    private function chmodLocal(string $path, int $permissions): void
    {
        if (chmod($path, $permissions)) {
            return;
        }

        throw new LocalSiteCertificateInstallFailure(
            errorCode: 'site_certificate.command_failed',
            message: 'Site certificate install command failed.',
            meta: [
                'command' => 'chmod',
                'exit_code' => null,
                'output' => "chmod failed for {$path}",
            ],
        );
    }

    private function writeSudoFile(string $path, string $contents): void
    {
        $process = new Process(['sudo', '-n', 'tee', $path]);
        $process->setInput($contents);
        $process->setTimeout(10);
        $process->run();

        if ($process->isSuccessful()) {
            return;
        }

        throw new LocalSiteCertificateInstallFailure(
            errorCode: 'site_certificate.write_failed',
            message: 'Site certificate file could not be written.',
            meta: [
                'path' => $path,
                'exit_code' => $process->getExitCode(),
                'output' => $this->output($process),
            ],
        );
    }

    private function chown(string $owner, string $path): void
    {
        $result = $this->runProcess(['sudo', '-n', 'chown', "{$owner}:{$owner}", $path]);

        if ($result->isSuccessful()) {
            return;
        }

        $this->mustRun(['sudo', '-n', 'chown', $owner, $path]);
    }

    private function shouldInstallAsCurrentUser(string $certPath, string $keyPath, ?string $owner): bool
    {
        if ($owner === null || str_starts_with($certPath, '/etc/orbit/certs/')) {
            return false;
        }

        if ($owner !== $this->currentUser()) {
            return false;
        }

        // Existing root-owned / non-writable cert material cannot be replaced via
        // the direct current-user write path. Force sudo tee + chown so the
        // desired owner receives writable certificate files.
        return $this->canWriteTargetsAsCurrentUser($certPath, $keyPath);
    }

    private function canWriteTargetsAsCurrentUser(string $certPath, string $keyPath): bool
    {
        foreach ([$certPath, $keyPath] as $path) {
            if (file_exists($path) && ! is_writable($path)) {
                return false;
            }
        }

        $directory = dirname($certPath);

        if (is_dir($directory) && ! is_writable($directory)) {
            return false;
        }

        return true;
    }

    private function currentUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $entry = posix_getpwuid(posix_geteuid());
            $user = is_array($entry) ? $entry['name'] ?? null : null;

            if (is_string($user) && $user !== '') {
                return $user;
            }
        }

        foreach (['USER', 'LOGNAME'] as $key) {
            $user = getenv($key);

            if (is_string($user) && $user !== '') {
                return $user;
            }
        }

        return '';
    }

    /**
     * @param  list<string>  $command
     */
    private function mustRun(array $command): Process
    {
        $result = $this->runProcess($command);

        if ($result->isSuccessful()) {
            return $result;
        }

        throw new LocalSiteCertificateInstallFailure(
            errorCode: 'site_certificate.command_failed',
            message: 'Site certificate install command failed.',
            meta: [
                'command' => $command[0],
                'exit_code' => $result->getExitCode(),
                'output' => $this->output($result),
            ],
        );
    }

    /**
     * @param  list<string>  $command
     */
    private function runProcess(array $command): Process
    {
        $process = new Process($command);
        $process->setTimeout(15);
        $process->run();

        return $process;
    }

    private function path(mixed $value, string $extension): string
    {
        if (! is_string($value) || $value === '' || str_contains($value, "\0")) {
            throw $this->invalidPath($extension);
        }

        if (! str_starts_with($value, '/') || ! str_ends_with($value, $extension)) {
            throw $this->invalidPath($extension);
        }

        if (! str_contains($value, '/.config/orbit/certs/') && ! str_starts_with($value, '/etc/orbit/certs/')) {
            throw $this->invalidPath($extension);
        }

        return $value;
    }

    private function contents(mixed $value, string $field): string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }

        throw new LocalSiteCertificateInstallFailure(
            errorCode: 'validation_failed',
            message: 'Site certificate contents must be non-empty strings.',
            meta: ['field' => $field],
        );
    }

    private function owner(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && preg_match('/\A[a-z_][a-z0-9_-]*[$]?\z/', $value) === 1) {
            return $value;
        }

        throw new LocalSiteCertificateInstallFailure(
            errorCode: 'validation_failed',
            message: 'Site certificate owner is invalid.',
            meta: ['field' => 'owner'],
        );
    }

    private function invalidPath(string $extension): LocalSiteCertificateInstallFailure
    {
        return new LocalSiteCertificateInstallFailure(
            errorCode: 'validation_failed',
            message: 'Site certificate path is invalid.',
            meta: ['extension' => $extension],
        );
    }

    private function output(Process $process): string
    {
        $output = trim($process->getErrorOutput());

        if ($output !== '') {
            return $output;
        }

        return trim($process->getOutput());
    }
}
