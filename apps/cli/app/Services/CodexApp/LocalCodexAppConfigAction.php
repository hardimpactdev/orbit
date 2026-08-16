<?php

declare(strict_types=1);

namespace App\Services\CodexApp;

use Illuminate\Filesystem\Filesystem;
use Throwable;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class LocalCodexAppConfigAction
{
    private const array ACTIONS = ['read', 'mutate'];

    private const array MUTATIONS = ['add', 'remove'];

    private const string RELATIVE_CONFIG_PATH = '.codex/codex-app/config.json';

    public function __construct(
        private Filesystem $files,
        private LocalCodexAppConfigMutation $mutation,
    ) {}

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

        $mutation = $this->mutation($payload['mutation'] ?? null);

        return $this->mutation->run(
            $this->configPath(),
            $mutation,
            $this->project($payload['project'] ?? null, $mutation),
        );
    }

    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    private function read(): array
    {
        $path = $this->configPath();

        if (! $this->files->isFile($path)) {
            return [
                'data' => [
                    'path' => $path,
                    'contents' => '{}',
                    'exists' => false,
                ],
                'meta' => [],
            ];
        }

        try {
            $contents = $this->files->get($path);
        } catch (Throwable $exception) {
            throw new LocalCodexAppConfigFailure(
                errorCode: 'codex_app.config_read_failed',
                message: 'Codex App config could not be read.',
                meta: [
                    'path' => $path,
                    'reason' => $exception->getMessage(),
                ],
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

    private function mutation(mixed $value): string
    {
        if (is_string($value) && in_array($value, self::MUTATIONS, strict: true)) {
            return $value;
        }

        throw new LocalCodexAppConfigFailure(
            errorCode: 'validation_failed',
            message: 'Codex App config mutation is invalid.',
            meta: ['field' => 'mutation'],
        );
    }

    /**
     * @return array{label: string, ssh_alias: string, remote_path?: string}
     */
    private function project(mixed $value, string $mutation): array
    {
        if (! is_array($value)) {
            throw new LocalCodexAppConfigFailure(
                errorCode: 'validation_failed',
                message: 'Codex App config project is invalid.',
                meta: ['field' => 'project'],
            );
        }

        $project = [
            'label' => $this->requiredString($value, 'label'),
            'ssh_alias' => $this->requiredString($value, 'ssh_alias'),
        ];

        if ($mutation === 'add') {
            $project['remote_path'] = $this->requiredString($value, 'remote_path');
        }

        return $project;
    }

    /**
     * @param  array<mixed>  $values
     *
     * @mago-expect analysis:mixed-assignment
     */
    private function requiredString(array $values, string $field): string
    {
        $value = $values[$field] ?? null;

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        throw new LocalCodexAppConfigFailure(
            errorCode: 'validation_failed',
            message: "Codex App config {$field} is required.",
            meta: ['field' => "project.{$field}"],
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
