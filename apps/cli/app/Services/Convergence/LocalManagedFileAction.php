<?php

declare(strict_types=1);

namespace App\Services\Convergence;

use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

final readonly class LocalManagedFileAction
{
    private const array Actions = ['probe', 'write'];

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
        $path = $this->path($payload['path'] ?? null);

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
        $exists = $this->runProcess(['sudo', 'test', '-f', $path]);

        if ($exists['exit_code'] !== 0) {
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

        $this->mustRun(['sudo', 'install', '-d', '-m', $directoryMode, $directory], 'managed_file.directory_failed');
        $this->mustRunWithInput(['sudo', 'tee', $path], $content, 'managed_file.write_failed');
        $this->mustRun(['sudo', 'chmod', $mode, $path], 'managed_file.chmod_failed');

        return [
            'path' => $path,
            'hash' => hash('sha256', $content),
            'mode' => $mode,
        ];
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

        foreach (self::AllowedPathPrefixes as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return $value;
            }
        }

        throw $this->invalidPath();
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
        foreach ([['sudo', 'sha256sum', $path], ['sudo', 'shasum', '-a', '256', $path]] as $command) {
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
        foreach ([
            ['sudo', 'stat', '-c', '%a',  $path],
            ['sudo', 'stat', '-f', '%Lp', $path],
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
