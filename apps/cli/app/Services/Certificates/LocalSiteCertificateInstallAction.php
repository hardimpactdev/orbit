<?php

declare(strict_types=1);

namespace App\Services\Certificates;

use Symfony\Component\Process\Process;

/**
 * @mago-expect lint:cyclomatic-complexity
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

        $this->mustRun(['sudo', 'install', '-d', '-m', '0755', dirname($certPath)]);
        $this->writeFile($certPath, $cert);
        $this->writeFile($keyPath, $key);
        $this->mustRun(['sudo', 'chmod', '0644', $certPath]);
        $this->mustRun(['sudo', 'chmod', '0600', $keyPath]);

        if ($owner !== null) {
            $this->chown($owner, $certPath);
            $this->chown($owner, $keyPath);
        }

        return [
            'cert_path' => $certPath,
            'key_path' => $keyPath,
            'owner' => $owner,
            'cert_bytes' => strlen($cert),
            'key_bytes' => strlen($key),
        ];
    }

    private function writeFile(string $path, string $contents): void
    {
        $process = new Process(['sudo', 'tee', $path]);
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
        $result = $this->runProcess(['sudo', 'chown', "{$owner}:{$owner}", $path]);

        if ($result->isSuccessful()) {
            return;
        }

        $this->mustRun(['sudo', 'chown', $owner, $path]);
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
