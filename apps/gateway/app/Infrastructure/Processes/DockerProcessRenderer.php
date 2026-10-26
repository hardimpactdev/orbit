<?php

declare(strict_types=1);

namespace App\Infrastructure\Processes;

use App\Domain\Processes\ProcessTarget;
use App\Models\Process;
use InvalidArgumentException;
use SensitiveParameter;

final readonly class DockerProcessRenderer
{
    public function containerName(#[SensitiveParameter] Process $process): string
    {
        if ($process->id < 1 || preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $process->name) !== 1) {
            throw new InvalidArgumentException('A process needs a persisted ID and safe name before rendering.');
        }

        return "orbit-process-{$process->id}-{$process->name}";
    }

    /**
     * The container definition that `process.converge-container` creates, apart from its name and labels. The
     * environment travels in the command's protected input, and the agent hands it to Docker in a root-only file.
     *
     * @return array{
     *     image: string,
     *     command: non-empty-list<string>,
     *     restart: string,
     *     working_directory: string|null,
     *     ports: list<string>,
     *     mounts: list<array{source: string, target: string, read_only: bool}>,
     *     environment: array<string, string>|object
     * }
     */
    public function containerInput(
        #[SensitiveParameter]
        Process $process,
        ProcessTarget $target,
    ): array {
        $runtimeConfig = $this->runtimeConfig($process);
        $image = $runtimeConfig['image'] ?? null;
        $command = $this->stringList($runtimeConfig['command'] ?? null);
        $this->containerName($process);

        if (
            ! is_string($image)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._\/:@-]*\z/D', $image) !== 1
            || $command === []
        ) {
            throw new InvalidArgumentException('A Docker process needs an image and command argv.');
        }

        $environment = $this->stringMap($process->runtime_config['environment'] ?? []);

        return [
            'image' => $image,
            'command' => $command,
            'restart' => $this->restartPolicy($process, $target),
            'working_directory' => $process->working_directory,
            'ports' => $this->stringList($runtimeConfig['ports'] ?? []),
            'mounts' => $this->volumes($runtimeConfig['volumes'] ?? []),
            // An empty map stays a JSON object.
            'environment' => $environment === [] ? (object) [] : $environment,
        ];
    }

    public function specHash(#[SensitiveParameter] Process $process, ProcessTarget $target): string
    {
        return hash('sha256', json_encode([
            'runtime_config' => $this->runtimeConfig($process),
            'working_directory' => $process->working_directory,
            'restart_policy' => $process->restart_policy,
            'node_id' => $target->node->id,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    private function runtimeConfig(#[SensitiveParameter] Process $process): array
    {
        $runtimeConfig = $process->runtime_config;

        if (array_key_exists('environment', $runtimeConfig)) {
            $runtimeConfig['environment'] = $this->stringMap($runtimeConfig['environment']);
        }

        return $runtimeConfig;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                return [];
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(#[SensitiveParameter] mixed $value): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Docker environment must be a string map.');
        }

        $items = [];

        foreach ($value as $name => $item) {
            if (! is_string($name) || ! is_string($item)) {
                throw new InvalidArgumentException('Docker environment values must be strings.');
            }

            if (
                preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) !== 1
                || str_contains($item, "\0")
                || str_contains($item, "\r")
                || str_contains($item, "\n")
            ) {
                throw new InvalidArgumentException('Docker environment needs safe names and single-line values.');
            }

            $items[$name] = $item;
        }

        ksort($items);

        return $items;
    }

    /**
     * @return list<array{source: string, target: string, read_only: bool}>
     */
    private function volumes(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $volumes = [];

        foreach ($value as $volume) {
            if (
                ! is_array($volume)
                || ! is_string($volume['source'] ?? null)
                || ! is_string($volume['target'] ?? null)
            ) {
                throw new InvalidArgumentException('Docker volumes need string source and target values.');
            }

            $volumes[] = [
                'source' => $volume['source'],
                'target' => $volume['target'],
                'read_only' => ($volume['read_only'] ?? false) === true,
            ];
        }

        return $volumes;
    }

    private function restartPolicy(#[SensitiveParameter] Process $process, ProcessTarget $target): string
    {
        $policy = match ($process->restart_policy) {
            'never' => 'no',
            'on-failure' => 'on-failure',
            'always' => 'always',
            'unless-stopped' => 'unless-stopped',
            default => throw new InvalidArgumentException('Unsupported Docker restart policy.'),
        };

        if ($target->onDemandHostStart && $policy === 'always') {
            return 'unless-stopped';
        }

        return $policy;
    }
}
