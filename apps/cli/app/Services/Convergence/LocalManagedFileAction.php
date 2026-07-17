<?php

declare(strict_types=1);

namespace App\Services\Convergence;

use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

final readonly class LocalManagedFileAction
{
    private const array Actions = ['probe', 'write'];

    private const string USER_ORBIT_PATH_PATTERN = '#^/(?:Users|home)/[A-Za-z0-9._-]+/(?:\.config/orbit|\.local/share/orbit)(?:/|$)#';

    private const array AllowedPathPrefixes = [
        '/etc/apt/apt.conf.d/',
        '/etc/orbit/',
        '/home/orbit/',
        '/srv/',
        '/var/www/',
    ];

    private const string ModePattern = '/\A[0-7]{3,4}\z/';

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function run(mixed $action, array $payload): array
    {
        $action = $this->action($action);
        $path = $this->accessibleHostPath($this->path($payload['path'] ?? null));

        if ($action === 'probe') {
            return $this->probe($path);
        }

        return $this->write(
            path: $path,
            content: $this->content($payload['content'] ?? null),
            mode: $this->mode($payload['mode'] ?? null, 'mode'),
            directoryMode: $this->mode($payload['directory_mode'] ?? null, 'directory_mode'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function probe(string $path): array
    {
        if (! $this->fileExists($path)) {
            return [
                'exists' => false,
                'hash' => null,
                'mode' => null,
            ];
        }

        return [
            'exists' => true,
            'hash' => $this->hash($path),
            'mode' => $this->fileMode($path),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function write(string $path, string $content, string $mode, string $directoryMode): array
    {
        $directory = dirname($path);
        $privilege = $this->isUserOrbitPath($path) ? [] : ['sudo', '-n'];

        $this->mustRun([
            ...$privilege,
            'install',
            '-d',
            '-m',
            $directoryMode,
            $directory,
        ], 'managed_file.directory_failed');
        $this->removeEmptyDirectoryAtPath($path, $privilege);
        $this->mustRunWithInput([...$privilege, 'tee', $path], $content, 'managed_file.write_failed');
        $this->mustRun([...$privilege, 'chmod', $mode, $path], 'managed_file.chmod_failed');

        return [
            'path' => $path,
            'hash' => hash('sha256', $content),
            'mode' => $mode,
        ];
    }

    /**
     * @param  list<string>  $privilege
     */
    private function removeEmptyDirectoryAtPath(string $path, array $privilege): void
    {
        if ($this->runProcess([...$privilege, 'test', '-d', $path])['exit_code'] !== 0) {
            return;
        }

        $this->mustRun(
            [...$privilege, 'rmdir', '--', $path],
            'managed_file.path_type_conflict',
        );
    }

    private function action(mixed $value): string
    {
        if (is_string($value) && in_array($value, self::Actions, true)) {
            return $value;
        }

        throw new LocalManagedFileFailure(
            errorCode: 'validation_failed',
            message: 'Managed file action is invalid.',
            meta: ['field' => 'action'],
        );
    }

    private function path(mixed $value): string
    {
        if (! is_string($value) || $value === '' || str_contains($value, "\0") || ! str_starts_with($value, '/')) {
            throw $this->invalidPath();
        }

        if ($this->containsPathTraversal($value)) {
            throw $this->invalidPath();
        }

        foreach (self::AllowedPathPrefixes as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return $value;
            }
        }

        if (preg_match(self::USER_ORBIT_PATH_PATTERN, $value) === 1) {
            return $value;
        }

        throw $this->invalidPath();
    }

    private function containsPathTraversal(string $path): bool
    {
        return array_any(explode('/', $path), static fn ($segment) => $segment === '.' || $segment === '..');
    }

    private function accessibleHostPath(string $path): string
    {
        $prefix = getenv('ORBIT_HOST_PATH_PREFIX');

        if (! is_string($prefix) || trim($prefix) === '') {
            return $path;
        }

        $prefix = rtrim(string: trim($prefix), characters: '/');

        if (
            ! str_starts_with($prefix, '/')
            || str_contains($prefix, "\0")
            || $this->containsPathTraversal($prefix)
        ) {
            throw new LocalManagedFileFailure(
                errorCode: 'managed_file.host_path_invalid',
                message: 'Managed file host path mapping is invalid.',
                meta: [],
            );
        }

        if ($path === $prefix || str_starts_with($path, "{$prefix}/")) {
            return $path;
        }

        return $prefix.$path;
    }

    private function isUserOrbitPath(string $path): bool
    {
        return preg_match(self::USER_ORBIT_PATH_PATTERN, $path) === 1;
    }

    private function fileExists(string $path): bool
    {
        if (is_file($path)) {
            return true;
        }

        return $this->runProcess(['sudo', '-n', 'test', '-f', $path])['exit_code'] === 0;
    }

    private function content(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        throw new LocalManagedFileFailure(
            errorCode: 'validation_failed',
            message: 'Managed file content must be a string.',
            meta: ['field' => 'content'],
        );
    }

    private function mode(mixed $value, string $field): string
    {
        if (is_string($value) && preg_match(self::ModePattern, $value) === 1) {
            return $value;
        }

        throw new LocalManagedFileFailure(
            errorCode: 'validation_failed',
            message: 'Managed file mode is invalid.',
            meta: ['field' => $field],
        );
    }

    private function invalidPath(): LocalManagedFileFailure
    {
        return new LocalManagedFileFailure(
            errorCode: 'validation_failed',
            message: 'Managed file path is invalid.',
            meta: ['field' => 'path'],
        );
    }

    private function hash(string $path): ?string
    {
        if (is_readable($path)) {
            $hash = hash_file('sha256', $path);

            if (is_string($hash) && $hash !== '') {
                return $hash;
            }
        }

        foreach ([['sudo', '-n', 'sha256sum', $path], ['sudo', '-n', 'shasum', '-a', '256', $path]] as $command) {
            $result = $this->runProcess($command);

            if ($result['exit_code'] !== 0) {
                continue;
            }

            $hash = strtok($result['output'], " \t\n\r");

            return is_string($hash) && $hash !== '' ? $hash : null;
        }

        return null;
    }

    private function fileMode(string $path): ?string
    {
        $mode = is_file($path) ? fileperms($path) : false;

        if (is_int($mode)) {
            return substr(sprintf('%o', $mode), -4);
        }

        foreach ([
            ['sudo', '-n', 'stat', '-c', '%a',  $path],
            ['sudo', '-n', 'stat', '-f', '%Lp', $path],
        ] as $command) {
            $result = $this->runProcess($command);

            if ($result['exit_code'] === 0 && trim($result['output']) !== '') {
                return trim($result['output']);
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $command
     */
    private function mustRun(array $command, string $errorCode): void
    {
        $result = $this->runProcess($command);

        if ($result['exit_code'] === 0) {
            return;
        }

        throw new LocalManagedFileFailure(
            errorCode: $errorCode,
            message: 'Managed file command failed.',
            meta: [
                'exit_code' => $result['exit_code'],
                'output' => $result['output'],
            ],
        );
    }

    /**
     * @param  list<string>  $command
     */
    private function mustRunWithInput(array $command, string $input, string $errorCode): void
    {
        $result = $this->runProcess($command, $input);

        if ($result['exit_code'] === 0) {
            return;
        }

        throw new LocalManagedFileFailure(
            errorCode: $errorCode,
            message: 'Managed file command failed.',
            meta: [
                'exit_code' => $result['exit_code'],
                'output' => $result['output'],
            ],
        );
    }

    /**
     * @param  list<string>  $command
     * @return array{exit_code: int, output: string}
     */
    private function runProcess(array $command, ?string $input = null): array
    {
        try {
            $process = new Process($command);
            $process->setTimeout(15);

            if ($input !== null) {
                $process->setInput($input);
            }

            $process->run();

            return [
                'exit_code' => $process->getExitCode() ?? 1,
                'output' => trim($process->getOutput().$process->getErrorOutput()),
            ];
        } catch (ProcessStartFailedException $exception) {
            return [
                'exit_code' => 127,
                'output' => $exception->getMessage(),
            ];
        }
    }
}
