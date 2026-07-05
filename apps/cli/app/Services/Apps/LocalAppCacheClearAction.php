<?php

declare(strict_types=1);

namespace App\Services\Apps;

use Symfony\Component\Process\Process;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class LocalAppCacheClearAction
{
    /**
     * @return array<string, mixed>
     */
    public function clear(mixed $path, mixed $phpVersion, mixed $runtimeUser): array
    {
        $path = $this->absolutePath($path);
        $phpVersion = $this->phpVersion($phpVersion);
        $runtimeUser = $this->runtimeUser($runtimeUser);
        $php = "/opt/orbit/php/{$phpVersion}/bin/php";
        $artisan = $this->mustRun([
            'sudo',
            '-u',
            $runtimeUser,
            '-H',
            $php,
            'artisan',
            'config:clear',
            '--no-interaction',
        ], $path);

        return [
            'path' => $path,
            'php_version' => $phpVersion,
            'runtime_user' => $runtimeUser,
            'artisan' => $artisan,
            'deleted_cache_files' => $this->deleteBootstrapCacheFiles($path),
        ];
    }

    /**
     * @param  list<string>  $command
     * @return array{command: list<string>, exit_code: int|null}
     */
    private function mustRun(array $command, string $cwd): array
    {
        $process = new Process($command, $cwd);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput());
            $error = $error !== '' ? $error : trim($process->getOutput());
            $error = $error !== '' ? $error : 'app cache clear command failed';

            throw new LocalAppCacheClearFailure(
                errorCode: 'app_cache_clear_failed',
                message: $error,
                meta: [
                    'command' => $command[4] ?? $command[0],
                    'exit_code' => $process->getExitCode(),
                ],
            );
        }

        return [
            'command' => $command,
            'exit_code' => $process->getExitCode(),
        ];
    }

    private function deleteBootstrapCacheFiles(string $path): int
    {
        $cachePath = $path.'/bootstrap/cache';

        if (! is_dir($cachePath)) {
            return 0;
        }

        $deleted = 0;
        $files = glob($cachePath.'/*');

        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            if (! is_file($file) || basename($file) === '.gitignore') {
                continue;
            }

            if (unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function absolutePath(mixed $value): string
    {
        if (is_string($value) && $value !== '' && str_starts_with($value, '/') && ! str_contains($value, "\0")) {
            return rtrim(string: $value, characters: '/');
        }

        throw new LocalAppCacheClearFailure(
            errorCode: 'validation_failed',
            message: 'App path must be an absolute path.',
            meta: ['field' => 'path'],
        );
    }

    private function phpVersion(mixed $value): string
    {
        if (is_string($value) && preg_match('/\A\d+\.\d+\z/', $value) === 1) {
            return $value;
        }

        throw new LocalAppCacheClearFailure(
            errorCode: 'validation_failed',
            message: 'PHP version is invalid.',
            meta: ['field' => 'php-version'],
        );
    }

    private function runtimeUser(mixed $value): string
    {
        if (is_string($value) && preg_match('/\A[a-z_][a-z0-9_-]*[$]?\z/', $value) === 1) {
            return $value;
        }

        throw new LocalAppCacheClearFailure(
            errorCode: 'validation_failed',
            message: 'Runtime user is invalid.',
            meta: ['field' => 'runtime-user'],
        );
    }
}
