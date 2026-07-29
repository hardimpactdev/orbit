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
        $artisanCommand = [
            'sudo',
            '-u',
            $runtimeUser,
            '-H',
            $php,
            'artisan',
            'config:clear',
            '--no-interaction',
        ];
        $artisan = $this->mustRun($artisanCommand, $path);

        return [
            'path' => $path,
            'php_version' => $phpVersion,
            'runtime_user' => $runtimeUser,
            'artisan' => $artisan,
            'deleted_cache_files' => $this->deleteBootstrapCacheFiles($path, $php, $runtimeUser),
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

    private function deleteBootstrapCacheFiles(string $path, string $php, string $runtimeUser): int
    {
        $cachePath = $path.'/bootstrap/cache';

        if (! is_dir($cachePath)) {
            return 0;
        }

        $process = new Process([
            'sudo',
            '-u',
            $runtimeUser,
            '-H',
            $php,
            '-r',
            <<<'PHP'
                $cachePath = $argv[1] ?? '';
                $files = glob($cachePath.'/*');

                if ($files === false) {
                    fwrite(STDERR, 'Unable to read the bootstrap cache directory.');
                    exit(1);
                }

                $deleted = 0;

                foreach ($files as $file) {
                    if (! is_file($file) || basename($file) === '.gitignore') {
                        continue;
                    }

                    if (! unlink($file)) {
                        fwrite(STDERR, 'Unable to delete a bootstrap cache file.');
                        exit(1);
                    }

                    $deleted++;
                }

                echo $deleted;
                PHP,
            '--',
            $cachePath,
        ], $path);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput());
            $error = $error !== '' ? $error : trim($process->getOutput());
            $error = $error !== '' ? $error : 'bootstrap cache deletion failed';

            throw new LocalAppCacheClearFailure(
                errorCode: 'app_cache_clear_failed',
                message: $error,
                meta: [
                    'command' => $php,
                    'exit_code' => $process->getExitCode(),
                ],
            );
        }

        $deleted = trim($process->getOutput());

        if (preg_match('/\A\d+\z/', $deleted) !== 1) {
            throw new LocalAppCacheClearFailure(
                errorCode: 'app_cache_clear_failed',
                message: 'Bootstrap cache deletion returned an invalid result.',
                meta: [
                    'command' => $php,
                    'exit_code' => $process->getExitCode(),
                ],
            );
        }

        return (int) $deleted;
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
