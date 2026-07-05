<?php

declare(strict_types=1);

namespace App\Services\CodexApp;

use Symfony\Component\Process\Process;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class LocalCodexAppConfigAction
{
    private const array ACTIONS = ['read', 'write', 'apply'];

    private const string RELATIVE_CONFIG_PATH = '.codex/codex-app/config.json';

    private const string APPLY_URL = 'codex://codex-app/apply-config';

    /**
     * @param  array<string, mixed>  $payload
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function run(array $payload): array
    {
        $action = $this->action($payload['action'] ?? null);

        if ($action === 'read') {
            return $this->read();
        }

        if ($action === 'write') {
            return $this->write($this->contents($payload['contents'] ?? null));
        }

        return $this->apply();
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function read(): array
    {
        $path = $this->configPath();

        if (! is_file($path)) {
            return [
                'data' => [
                    'path' => $path,
                    'contents' => '{}',
                    'exists' => false,
                ],
                'meta' => [],
            ];
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new LocalCodexAppConfigFailure(
                errorCode: 'codex_app.config_read_failed',
                message: 'Codex App config could not be read.',
                meta: ['path' => $path],
            );
        }

        return [
            'data' => [
                'path' => $path,
                'contents' => $contents,
                'exists' => true,
            ],
            'meta' => [],
        ];
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function write(string $contents): array
    {
        $path = $this->configPath();
        $directory = dirname($path);

        if (
            ! is_dir($directory)
            && ! mkdir(directory: $directory, permissions: 0o700, recursive: true)
            && ! is_dir($directory)
        ) {
            throw new LocalCodexAppConfigFailure(
                errorCode: 'codex_app.config_write_failed',
                message: 'Codex App config directory could not be created.',
                meta: ['path' => $path],
            );
        }

        chmod(filename: $directory, permissions: 0o700);

        $temporary = tempnam(directory: $directory, prefix: 'config.json.');

        if (! is_string($temporary)) {
            throw new LocalCodexAppConfigFailure(
                errorCode: 'codex_app.config_write_failed',
                message: 'Codex App config temporary file could not be created.',
                meta: ['path' => $path],
            );
        }

        try {
            if (file_put_contents($temporary, $contents) === false) {
                throw new LocalCodexAppConfigFailure(
                    errorCode: 'codex_app.config_write_failed',
                    message: 'Codex App config could not be written.',
                    meta: ['path' => $path],
                );
            }

            chmod(filename: $temporary, permissions: 0o600);

            if (! rename($temporary, $path)) {
                throw new LocalCodexAppConfigFailure(
                    errorCode: 'codex_app.config_write_failed',
                    message: 'Codex App config could not be moved into place.',
                    meta: ['path' => $path],
                );
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return [
            'data' => [
                'path' => $path,
                'bytes' => strlen($contents),
            ],
            'meta' => [],
        ];
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function apply(): array
    {
        $process = new Process(['open', self::APPLY_URL]);
        $process->setTimeout(15);
        $process->run();

        if (! $process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput());
            $stdout = trim($process->getOutput());

            throw new LocalCodexAppConfigFailure(
                errorCode: 'codex_app.apply_failed',
                message: 'Codex App config apply callback failed.',
                meta: [
                    'exit_code' => $process->getExitCode(),
                    'stderr' => $stderr !== '' ? $stderr : $stdout,
                ],
            );
        }

        return [
            'data' => [
                'url' => self::APPLY_URL,
                'exit_code' => $process->getExitCode(),
            ],
            'meta' => [],
        ];
    }

    private function action(mixed $value): string
    {
        if (is_string($value) && in_array($value, self::ACTIONS, strict: true)) {
            return $value;
        }

        throw new LocalCodexAppConfigFailure(
            errorCode: 'validation_failed',
            message: 'Codex App config action is invalid.',
            meta: ['field' => 'action'],
        );
    }

    private function contents(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        throw new LocalCodexAppConfigFailure(
            errorCode: 'validation_failed',
            message: 'Codex App config contents must be a string.',
            meta: ['field' => 'contents'],
        );
    }

    private function configPath(): string
    {
        $home = getenv('HOME');

        if (! is_string($home) || trim($home) === '' || str_contains($home, "\0")) {
            throw new LocalCodexAppConfigFailure(
                errorCode: 'codex_app.home_unavailable',
                message: 'Codex App config home directory is unavailable.',
                meta: [],
            );
        }

        return rtrim(string: $home, characters: '/').'/'.self::RELATIVE_CONFIG_PATH;
    }
}
